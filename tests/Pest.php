<?php

use Shazzoo\StrategyEngine\Tests\TestCase;

// De unit-tests raken Laravel niet aan en draaien zonder applicatie. De
// feature-tests draaien in een Testbench-app met de plugin geladen.
uses(TestCase::class)->in('Feature');
