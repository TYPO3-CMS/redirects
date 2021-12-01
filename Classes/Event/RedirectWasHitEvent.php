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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * This event is fired when a request matches a configured redirect
 *
 * This allows to further process the matched redirect and adjusting the PSR-7 response
 */
final class RedirectWasHitEvent
{
    private ServerRequestInterface $request;
    private ResponseInterface $response;
    private array $matchedRedirect;
    private UriInterface $targetUrl;

    public function __construct(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $matchedRedirect,
        UriInterface $targetUrl
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->matchedRedirect = $matchedRedirect;
        $this->targetUrl = $targetUrl;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getTargetUrl(): UriInterface
    {
        return $this->targetUrl;
    }

    public function setMatchedRedirect(array $matchedRedirect): void
    {
        $this->matchedRedirect = $matchedRedirect;
    }

    public function getMatchedRedirect(): array
    {
        return $this->matchedRedirect;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
