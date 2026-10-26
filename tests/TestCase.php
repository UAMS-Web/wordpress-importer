<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for WordPress Importer package tests that do not require the
 * Laravel application `Tests\TestCase`.
 */
abstract class TestCase extends PHPUnitTestCase {}
