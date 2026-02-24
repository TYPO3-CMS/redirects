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

namespace TYPO3\CMS\Redirects\Event;

/**
 * This event is fired in \TYPO3\CMS\Redirects\Service\IntegrityService->checkRedirectTargetIntegrity()
 * for each redirect record.
 *
 * It can be used to perform custom validation on redirect targets and flag broken or invalid targets.
 */
final class RedirectIntegrityCheckEvent
{
    private ?string $integrityStatus = null;

    /**
     * @param array<string, string|int|float|null> $redirect
     */
    public function __construct(
        private readonly array $redirect,
    ) {}

    /**
     * @return array<string, string|int|float|null>
     */
    public function getRedirect(): array
    {
        return $this->redirect;
    }

    public function getUid(): int
    {
        return $this->redirect['uid'];
    }

    public function getPid(): int
    {
        return $this->redirect['pid'];
    }

    public function getDeleted(): bool
    {
        return ((int)($this->redirect['deleted'] ?? 0)) === 1;
    }

    public function getDisabled(): bool
    {
        return ((int)($this->redirect['disabled'] ?? 0)) === 1;
    }

    public function getSourceHost(): string
    {
        return $this->redirect['source_host'];
    }

    public function getSourcePath(): string
    {
        return $this->redirect['source_path'];
    }

    public function getIsRegExp(): bool
    {
        return ((int)($this->redirect['is_regexp'] ?? 0)) === 1;
    }

    public function getProtected(): bool
    {
        return ((int)($this->redirect['protected'] ?? 0)) === 1;
    }

    public function getForceHttps(): bool
    {
        return ((int)($this->redirect['force_https'] ?? 0)) === 1;
    }

    public function getRespectQueryParameters(): bool
    {
        return ((int)($this->redirect['respect_query_parameters'] ?? 0)) === 1;
    }

    public function getKeepQueryParameters(): bool
    {
        return ((int)($this->redirect['keep_query_parameters'] ?? 0)) === 1;
    }

    public function getTarget(): string
    {
        return (string)($this->redirect['target'] ?? '');
    }

    public function getTargetStatusCode(): int
    {
        return (int)$this->redirect['target_statuscode'];
    }

    public function getCreationType(): int
    {
        return (int)$this->redirect['creation_type'];
    }

    public function getOriginalIntegrityStatus(): string
    {
        return $this->redirect['integrity_status'];
    }

    /**
     * Be aware that this has been possible set by another earlier PSR-14 event listener already.
     * Could be any of the {@see RedirectConflict} constants, a custom value or `NULL`. In case
     * of `NULL` no further handlinge are processed or regonized as conflict during the integrity
     * checks.
     *
     * This is not initialized with the `sys_redirect.integirty_status` value.
     */
    public function getIntegrityStatus(): ?string
    {
        return $this->integrityStatus;
    }

    /**
     * Set the integrity status, could be one of the {@see RedirectConflict} constants, a custom value or `NULL`.
     * In case of `NULL` no further handlinge are processed or regonized as conflict during the integrity checks.
     */
    public function setIntegrityStatus(?string $integrityStatus): void
    {
        $this->integrityStatus = $integrityStatus;
    }
}
