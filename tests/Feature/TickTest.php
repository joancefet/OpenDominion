<?php

namespace OpenDominion\Tests\Feature;

use Artisan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenDominion\Calculators\Dominion\PopulationCalculator;
use OpenDominion\Calculators\Dominion\ProductionCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Services\Dominion\Actions\SpellActionService;
use OpenDominion\Services\Dominion\QueueService;
use OpenDominion\Services\Dominion\TickService;
use OpenDominion\Tests\AbstractBrowserKitTestCase;
use Throwable;

class TickTest extends AbstractBrowserKitTestCase
{
    use DatabaseTransactions;

    // todo: add test: dominion shouldnt tick on hour 0

    public function testMoraleTick()
    {
        $user = $this->createUser();
        $round = $this->createRound('last week');
        $dominion = $this->createDominion($user, $round);

        $dominion->protection_ticks_remaining = 0;
        $dominion->morale = 74;
        $dominion->save();

        // todo: call tickservice instead for tickHourly (so it doesnt trigger tickDaily when tests run at 00:00 UTC)

        // Test +6 morale below 80
        Artisan::call('game:tick');
        $this->seeInDatabase('dominions', ['id' => $dominion->id, 'morale' => 80]);

        // Test +3 morale above 80
        Artisan::call('game:tick');
        $this->seeInDatabase('dominions', ['id' => $dominion->id, 'morale' => 83]);
    }

    /**
     * @throws Throwable
     */
    public function testQueuesTick()
    {
        $user = $this->createUser();
        $round = $this->createRound('last week');
        $dominion = $this->createDominion($user, $round);
        $queueService = app(QueueService::class);

        $dominion->protection_ticks_remaining = 0;
        $dominion->land_plain = 0;
        $dominion->building_home = 0;
        $dominion->save();

        $queueService->queueResources('exploration', $dominion, ['land_plain' => 10], 3);
        $queueService->queueResources('exploration', $dominion, ['land_plain' => 5], 2);
        $queueService->queueResources('construction', $dominion, ['building_home' => 10], 3);

        // Test queue hours 3 -> 2
        Artisan::call('game:tick');
        $this
            ->seeInDatabase('dominions', ['id' => $dominion->id, 'land_plain' => 0, 'building_home' => 0])
            ->seeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'exploration', 'resource' => 'land_plain', 'hours' => 2, 'amount' => 10])
            ->seeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'construction', 'resource' => 'building_home', 'hours' => 2, 'amount' => 10]);

        // Test queue hours 2 -> 1
        Artisan::call('game:tick');
        $this
            ->seeInDatabase('dominions', ['id' => $dominion->id, 'land_plain' => 5, 'building_home' => 0])
            ->seeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'exploration', 'resource' => 'land_plain', 'hours' => 1, 'amount' => 10])
            ->seeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'construction', 'resource' => 'building_home', 'hours' => 1, 'amount' => 10]);

        // Test queues get processed on hour 0
        Artisan::call('game:tick');
        $this
            ->seeInDatabase('dominions', ['id' => $dominion->id, 'land_plain' => 15, 'building_home' => 10])
            ->dontSeeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'exploration', 'resource' => 'land_plain'])
            ->dontSeeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'construction', 'resource' => 'building_home']);
    }

    /**
     * @throws Throwable
     */
    public function testQueueShouldntTickLockedDominions()
    {
        $user = $this->createUser();
        $round = $this->createRound('-2 days', '-1 days');
        $dominion = $this->createDominion($user, $round);
        $queueService = app(QueueService::class);

        $dominion->fill([
            'peasants' => 0,
            'morale' => 0,
            'spy_strength' => 0,
            'wizard_strength' => 0,
            'resource_platinum' => 0,
            'building_home' => 100,
            'building_alchemy' => 100,
            'protection_ticks_remaining' => 0,
        ])->save();

        $this->assertTrue($dominion->isLocked());

        $queueService->queueResources('exploration', $dominion, ['land_plain' => 10], 3);
        $queueService->queueResources('construction', $dominion, ['building_home' => 10], 3);

        Artisan::call('game:tick');

        $this
            ->seeInDatabase('dominions', [
                'id' => $dominion->id,
                'peasants' => 0,
                'morale' => 0,
                'spy_strength' => 0,
                'wizard_strength' => 0,
                'resource_platinum' => 0,
            ])
            ->seeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'exploration', 'resource' => 'land_plain', 'hours' => 3, 'amount' => 10])
            ->seeInDatabase('dominion_queue', ['dominion_id' => $dominion->id, 'source' => 'construction', 'resource' => 'building_home', 'hours' => 3, 'amount' => 10]);
    }

    public function testResourcesGetGeneratedOnTheSameHourThatBuildingsComeIn()
    {
        $user = $this->createUser();
        $round = $this->createRound('last week');
        $dominion = $this->createDominion($user, $round);
        $queueService = app(QueueService::class);
        $tickService = app(TickService::class);

        $dominion->protection_ticks_remaining = 0;
        $dominion->resource_gems = 0;
        $dominion->resource_mana = 0;
        $dominion->save();

        $queueService->queueResources('construction', $dominion, ['building_diamond_mine' => 20], 1);
        $queueService->queueResources('construction', $dominion, ['building_tower' => 20], 1);
        // Manually precalculate when queuing for next hour
        $tickService->precalculateTick($dominion);

        Artisan::call('game:tick');

        $this->seeInDatabase('dominions', [
            'id' => $dominion->id,
            'resource_gems' => 300,
            'resource_mana' => 500,
        ]);
    }

    // https://github.com/WaveHack/OpenDominion/issues/217
    public function testTheProperAmountOfPlatinumGetsAddedOnTick()
    {
        $user1 = $this->createUser();
        $user2 = $this->createUser();
        $round = $this->createRound('last week');
        $dominion1 = $this->createDominion($user1, $round);
        $dominion2 = $this->createDominion($user2, $round);

        $populationCalculator = $this->app->make(PopulationCalculator::class);
        $productionCalculator = $this->app->make(ProductionCalculator::class);
        $spellActionService = $this->app->make(SpellActionService::class);
        $spellCalculator = $this->app->make(SpellCalculator::class);

        $dominion1->fill([
            'peasants' => 30000,
            'resource_platinum' => 1000,
            'resource_mana' => 9999999,
            'building_alchemy' => 850,
            'protection_ticks_remaining' => 0,
        ])->save();

        // just duplicate values, yolo
        $dominion2->fill([
            'peasants' => 30000,
            'resource_platinum' => 1000,
            'resource_mana' => 9999999,
            'building_alchemy' => 850,
            'protection_ticks_remaining' => 0,
        ])->save();

        // 850 alch * 45 plat = 38,250 plat
        // 18k jobs * 2.7 plat = 48,600 plat

        $platToBeAdded = 86850;

        $this->assertEquals(18000, $populationCalculator->getPopulationEmployed($dominion1));
        $this->assertEquals($platToBeAdded, $productionCalculator->getPlatinumProduction($dominion1));
        $this->assertFalse($spellCalculator->isSpellActive($dominion1, 'midas_touch'));

        $this->assertEquals(18000, $populationCalculator->getPopulationEmployed($dominion2));
        $this->assertEquals($platToBeAdded, $productionCalculator->getPlatinumProduction($dominion2));
        $this->assertFalse($spellCalculator->isSpellActive($dominion2, 'midas_touch'));

        // cast self spell for dominion 2 ONLY

        $spellActionService->castSpell($dominion2, 'midas_touch');

        // Refresh active spells
        $spellCalculator->getActiveSpells($dominion1, true);
        $this->assertFalse($spellCalculator->isSpellActive($dominion1, 'midas_touch'));
        $this->assertEquals($platToBeAdded * 1.0, $productionCalculator->getPlatinumProduction($dominion1));

        $spellCalculator->getActiveSpells($dominion2, true);
        $this->assertTrue($spellCalculator->isSpellActive($dominion2, 'midas_touch'));
        $this->assertEquals($platToBeAdded * 1.1, $productionCalculator->getPlatinumProduction($dominion2));

        Artisan::call('game:tick');
        $dominion1->refresh();
        $dominion2->refresh();

        $this->assertEquals(1000 + $platToBeAdded * 1.0, $dominion1->resource_platinum);
        $this->assertEquals(1000 + $platToBeAdded * 1.1, $dominion2->resource_platinum);
    }

    // https://github.com/WaveHack/OpenDominion/issues/227
    public function testTheProperAmountOfFoodGetsAddedOnTick()
    {
        $user = $this->createUser();
        $round = $this->createRound('last week');
        $tickService = app(TickService::class);
        // don't use a race that has food-related perks
        $race = \OpenDominion\Models\Race::where('name', 'Human')->first();
        $dominion = $this->createDominion($user, $round, $race);

        $productionCalculator = $this->app->make(ProductionCalculator::class);
        $spellActionService = $this->app->make(SpellActionService::class);
        $spellCalculator = $this->app->make(SpellCalculator::class);

        $dominion->fill([
            'peasants' => 7553 * 4, // each person eats 0.25 food /hr
            'resource_food' => 27487,
            'resource_mana' => 9999999,
            'building_farm' => 80,
            'military_draftees' => 0,
            'military_unit2' => 0,
            'military_spies' => 0,
            'military_wizards' => 0,
            'protection_ticks_remaining' => 0,
        ])->save();

        // 80 farms * 80 food * 1.05 human * 1.025 prestige = 6888 food
        $this->assertEquals(6888, $productionCalculator->getFoodProduction($dominion));
        $this->assertEquals(7553, $productionCalculator->getFoodConsumption($dominion));
        $this->assertEquals(275, round($productionCalculator->getFoodDecay($dominion)));

        // 6888 - 7553 - 275 = -940 food
        $this->assertEquals(-940, $productionCalculator->getFoodNetChange($dominion));

        $spellActionService->castSpell($dominion, 'gaias_watch');

        // Refresh active spells
        $spellCalculator->getActiveSpells($dominion, true);

        // 80 farms * 80 food * 1.15 human+gaias * 1.025 prestige = 7544 food
        // Note: Prestige calc got changed around 2019-06-05, calculation for
        // food production multiplier and max pop is slightly different now
        $this->assertEquals(7544, $productionCalculator->getFoodProduction($dominion));
        $this->assertEquals(7553, $productionCalculator->getFoodConsumption($dominion));
        $this->assertEquals(275, round($productionCalculator->getFoodDecay($dominion)));

        // 7544 - 7553 - 275 = -284
        $this->assertEquals(-284, $productionCalculator->getFoodNetChange($dominion));

        $tickService->precalculateTick($dominion);
        Artisan::call('game:tick');
        $dominion->refresh();

        // 27487 food - 284 net change = 27203 food
        $this->assertEquals(27203, $dominion->resource_food);
    }
}
