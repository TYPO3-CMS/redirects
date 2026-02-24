<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Redirects\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Redirects\Event\RedirectIntegrityCheckEvent;
use TYPO3\CMS\Redirects\Utility\RedirectConflict;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RedirectIntegrityCheckEventTest extends UnitTestCase
{
    #[Test]
    public function gettersReturnConstructorSetValues(): void
    {
        $redirect = [
            'uid' => 1,
            'deleted' => 1,
            'disabled' => 1,
            'source_host' => 'example.com',
            'source_path' => '/old-page',
            'is_regexp' => 1,
            'protected' => 1,
            'force_https' => 1,
            'respect_query_parameters' => 1,
            'keep_query_parameters' => 1,
            'target_statuscode' => 301,
            'integrity_status' => RedirectConflict::INVALID_TARGET,
            'creation_type' => 1,
            'target' => 'https://example.com/new-page',
        ];
        $event = new RedirectIntegrityCheckEvent($redirect);

        self::assertSame($redirect, $event->getRedirect());
        self::assertTrue($event->getDeleted());
        self::assertTrue($event->getDisabled());
        self::assertSame($redirect['source_host'], $event->getSourceHost());
        self::assertSame($redirect['source_path'], $event->getSourcePath());
        self::assertSame('https://example.com/new-page', $event->getTarget());
        self::assertTrue($event->getIsRegExp());
        self::assertTrue($event->getProtected());
        self::assertTrue($event->getForceHttps());
        self::assertTrue($event->getRespectQueryParameters());
        self::assertTrue($event->getKeepQueryParameters());
        self::assertSame($redirect['target_statuscode'], $event->getTargetStatusCode());
        self::assertSame($redirect['creation_type'], $event->getCreationType());
        self::assertSame($redirect['integrity_status'], $event->getOriginalIntegrityStatus());
        self::assertNull($event->getIntegrityStatus());
    }

    #[Test]
    public function getTargetReturnsEmptyStringWhenTargetFieldIsMissing(): void
    {
        $event = new RedirectIntegrityCheckEvent(['uid' => 1]);
        self::assertSame('', $event->getTarget());
    }

    #[Test]
    public function integrityStatusCanBeSetRetrievedAndResetToNull(): void
    {
        $event = new RedirectIntegrityCheckEvent(['uid' => 1, 'target' => '/page']);

        $event->setIntegrityStatus('broken_target');
        self::assertSame('broken_target', $event->getIntegrityStatus());

        $event->setIntegrityStatus(null);
        self::assertNull($event->getIntegrityStatus());
    }
}
