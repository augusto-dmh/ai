<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

trait HandlesFailoverErrors
{
    /**
     * Execute a callback with failoverable error handling.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withErrorHandling(string $providerName, Closure $callback): mixed
    {
        try {
            return $this->retryOnceAfterDelay($callback);
        } catch (ConnectionException $connectionException) {
            throw ProviderConnectionException::forProvider(
                $providerName, $connectionException->getCode(), $connectionException
            );
        } catch (RequestException $requestException) {
            if ($requestException->response !== null) {
                $status = $requestException->response->status();

                if ($status === 429) {
                    throw RateLimitedException::forProvider(
                        $providerName, $requestException->getCode(), $requestException
                    );
                }

                if ($status === 402) {
                    throw InsufficientCreditsException::forProvider(
                        $providerName, $requestException->getCode(), $requestException
                    );
                }

                if (in_array($status, $this->overloadedStatusCodes())) {
                    throw ProviderOverloadedException::forProvider(
                        $providerName, $requestException->getCode(), $requestException
                    );
                }

                if ($patterns = $this->insufficientCreditPatterns()) {
                    $message = strtolower($requestException->response->json('error.message', ''));

                    foreach ($patterns as $pattern) {
                        if (str_contains($message, (string) $pattern)) {
                            throw InsufficientCreditsException::forProvider(
                                $providerName, $requestException->getCode(), $requestException
                            );
                        }
                    }
                }
            }

            throw $requestException;
        }
    }

    /**
     * Execute a callback, retrying it once when a rate limited or overloaded provider asks to retry shortly.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function retryOnceAfterDelay(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (RequestException $requestException) {
            $status = $requestException->response?->status();
            $retryAfter = $requestException->response?->header('Retry-After');

            // Waiting any longer than this is left to failover...
            if (($status !== 429 && ! in_array($status, $this->overloadedStatusCodes())) ||
                ! is_numeric($retryAfter) || $retryAfter < 0 || $retryAfter > 10) {
                throw $requestException;
            }

            Sleep::for((int) ceil((float) $retryAfter))->seconds();

            return $callback();
        }
    }

    /**
     * The status codes that indicate a provider is transiently unavailable and the request should fail over.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [502, 503, 504, 520, 522, 524];
    }

    /**
     * The patterns used to detect insufficient credits or quota errors.
     *
     * @return list<string>
     */
    protected function insufficientCreditPatterns(): array
    {
        return [];
    }
}
