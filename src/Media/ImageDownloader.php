<?php

namespace ByteMinus\AiBlog\Media;

use ByteMinus\AiBlog\Fetching\Exceptions\FetchRefused;
use ByteMinus\AiBlog\Fetching\FetchRefusal;
use ByteMinus\AiBlog\Fetching\FetchTarget;
use ByteMinus\AiBlog\Fetching\HostResolver;
use ByteMinus\AiBlog\Fetching\IpAddressPolicy;
use ByteMinus\AiBlog\Fetching\SafeFetcher;
use ByteMinus\AiBlog\Fetching\UrlGuard;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Downloads one image from an address an agent supplied: a public https:// URL, or the temporary
 * download_url ChatGPT hands over for a file.
 *
 * It holds the same line as the research {@see SafeFetcher}, on the first URL
 * and on every redirect: {@see UrlGuard} (no credentials, no internal host names, no shorthand numeric hosts),
 * every resolved address must pass {@see IpAddressPolicy}, and the connection is pinned to the checked address
 * so DNS rebinding cannot move it inside. Unlike that fetcher it runs inside a web request (an MCP call is
 * one), skips robots.txt (the person asked for this exact file), accepts only https on port 443, and has a
 * tighter time budget. The body is capped on the wire by cURL's progress callback.
 */
class ImageDownloader
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly IpAddressPolicy $policy,
        private readonly UrlGuard $guard,
    ) {}

    public function download(string $url, int $maxBytes): string
    {
        $redirects = (int) config('ai-blog.media.fetch_max_redirects', 3);

        for ($hop = 0; $hop <= $redirects; $hop++) {
            $target = $this->target($url);
            $address = $this->address($target->host, $target->literalIp);
            $pinned = str_contains($address, ':') ? '['.$address.']' : $address;

            try {
                $response = Http::withoutRedirecting()
                    ->withHeaders(['User-Agent' => 'LaravelAiBlog-Media/1.0', 'Accept' => 'image/png, image/jpeg, image/webp'])
                    ->withOptions([
                        'timeout' => (int) config('ai-blog.media.fetch_timeout', 20),
                        'connect_timeout' => 5,
                        'cookies' => false,
                        'http_errors' => false,
                        'proxy' => ['no' => ['*']],
                        'protocols' => ['https'],
                        'curl' => [
                            CURLOPT_RESOLVE => [$target->host.':443:'.$pinned],
                            CURLOPT_FRESH_CONNECT => true,
                            CURLOPT_FORBID_REUSE => true,
                        ],
                        'progress' => fn (int $total, int $done): bool => $done > $maxBytes || $total > $maxBytes,
                    ])
                    ->get($target->url);
            } catch (ConnectionException|TransferException $e) {
                if (str_contains(strtolower($e->getMessage()), 'aborted by the progress callback')) {
                    throw new MediaException($this->tooLarge($maxBytes));
                }

                throw new MediaException('The image could not be downloaded: the server did not answer in time.');
            }

            if (in_array($response->status(), self::REDIRECT_STATUSES, true)) {
                $location = trim((string) $response->header('Location'));
                try {
                    $url = (string) UriResolver::resolve(new Uri($target->url), new Uri($location))->withFragment('');
                } catch (InvalidArgumentException) {
                    $location = '';
                }
                if ($location === '') {
                    throw new MediaException('The image address redirected somewhere invalid.');
                }

                continue;
            }

            if (! $response->successful()) {
                throw new MediaException("The image could not be downloaded (HTTP {$response->status()}).");
            }

            $length = $response->header('Content-Length');
            $body = $response->body();
            if ((ctype_digit((string) $length) && (int) $length > $maxBytes) || strlen($body) > $maxBytes) {
                throw new MediaException($this->tooLarge($maxBytes));
            }

            return $body;
        }

        throw new MediaException('The image address redirected too many times.');
    }

    private function target(string $url): FetchTarget
    {
        try {
            $target = $this->guard->check($url);
        } catch (FetchRefused $refused) {
            throw new MediaException($this->refusal($refused->reason));
        }

        if ($target->scheme !== 'https') {
            throw new MediaException('Only https:// image addresses can be downloaded.');
        }
        if ($target->port !== 443) {
            throw new MediaException('Only image addresses on the standard https port can be downloaded.');
        }

        return $target;
    }

    private function address(string $host, ?string $literalIp): string
    {
        $addresses = $literalIp !== null ? [$literalIp] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new MediaException('The image address does not resolve.');
        }
        foreach ($addresses as $address) {
            if (! $this->policy->allows($address)) {
                throw new MediaException($this->refusal(FetchRefusal::BlockedAddress));
            }
        }
        $ipv4 = array_values(array_filter($addresses, fn (string $address): bool => ! str_contains($address, ':')));

        return $ipv4[0] ?? $addresses[0];
    }

    private function refusal(FetchRefusal $reason): string
    {
        return match ($reason) {
            FetchRefusal::UnsupportedScheme => 'Only https:// image addresses can be downloaded.',
            FetchRefusal::BlockedHostname, FetchRefusal::BlockedAddress => 'That image address points at a private network and cannot be downloaded.',
            default => 'That image address is not allowed: '.$reason->describe().'.',
        };
    }

    private function tooLarge(int $maxBytes): string
    {
        return 'That image is larger than '.round($maxBytes / 1048576, 1).' MB.';
    }
}
