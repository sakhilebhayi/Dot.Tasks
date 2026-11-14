<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether a company holds this platform, as InfoDot records it.
 *
 * InfoDot decides which platforms a company holds. Sign-in already carries that answer; this asks again while a
 * person is signed in, so access that ends at InfoDot ends here too. The answer is about the company only and is
 * kept for ten minutes. Anything other than a clear answer (InfoDot unreachable, the secret refused, an unknown
 * company, a body that does not name this platform) is "unknown": the caller carries on as before, the failure is
 * kept for a minute and logged once in that minute. An outage at InfoDot never locks a company out.
 */
class CompanyEntitlement
{
    private const ANSWER_SECONDS = 600;

    private const FAILURE_SECONDS = 60;

    /** Session key: what redeem said of the person's personal workspace for this platform (true or false). */
    public const PERSONAL_SESSION_KEY = 'ecosystem.personal_entitled';

    /**
     * The `entitled` value redeem gave the person's personal workspace, or null when it gave none.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function personalAnswer(array $identity): ?bool
    {
        foreach ((array) ($identity['teams'] ?? []) as $team) {
            if (is_array($team) && ($team['personal'] ?? false) && is_bool($team['entitled'] ?? null)) {
                return $team['entitled'];
            }
        }

        return null;
    }

    public function enabled(): bool
    {
        return (bool) config('ecosystem.entitlement.check', true);
    }

    /** What a redeemed company says about itself: an absent key is an older InfoDot, which is not a refusal. */
    public static function admits(mixed $team): bool
    {
        return is_array($team) && (! array_key_exists('entitled', $team) || $team['entitled'] === true);
    }

    /**
     * @return array{entitled: bool, status: string}|null null when InfoDot gave no clear answer
     */
    public function of(string $teamUuid): ?array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$teamUuid;

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && array_key_exists('answer', $cached)) {
            return $cached['answer'];
        }

        $answer = $this->ask($teamUuid);

        try {
            Cache::put($key, ['answer' => $answer], $answer === null ? self::FAILURE_SECONDS : self::ANSWER_SECONDS);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

        return $answer;
    }

    /** Where a person manages this platform for their company. */
    public function manageUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.rawurlencode($this->platform());
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'tasks');
    }

    /** InfoDot's read endpoint, on the same host this app redeems sign-in codes at. */
    private function url(string $teamUuid): ?string
    {
        $configured = config('ecosystem.entitlement.url');

        if (is_string($configured) && $configured !== '') {
            return str_replace('{uuid}', rawurlencode($teamUuid), $configured);
        }

        $redeem = parse_url((string) config('ecosystem.infodot.redeem_url', ''));

        if (! is_array($redeem) || empty($redeem['scheme']) || empty($redeem['host'])) {
            return null;
        }

        return $redeem['scheme'].'://'.$redeem['host'].(isset($redeem['port']) ? ':'.$redeem['port'] : '')
            .'/api/ecosystem/companies/'.rawurlencode($teamUuid).'/platforms';
    }

    /**
     * @return array{entitled: bool, status: string}|null
     */
    private function ask(string $teamUuid): ?array
    {
        $bearer = config('ecosystem.infodot.secret');
        $url = $this->url($teamUuid);

        if (! is_string($bearer) || $bearer === '' || $url === null) {
            return $this->unknown('not configured');
        }

        try {
            $response = Http::withToken($bearer)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->timeout((int) config('ecosystem.entitlement.timeout', 3))
                ->get($url, ['platform' => $this->platform()]);

            if (! $response->successful()) {
                return $this->unknown('status '.$response->status());
            }

            $body = $response->json();
        } catch (Throwable $e) {
            return $this->unknown(class_basename($e));
        }

        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows) || ($body['company']['uuid'] ?? null) !== $teamUuid) {
            return $this->unknown('malformed body');
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $this->platform()) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return $this->unknown('malformed body');
                }

                return ['entitled' => $row['entitled'], 'status' => is_string($row['status'] ?? null) ? $row['status'] : ''];
            }
        }

        return $this->unknown('own row missing');
    }

    /** Logged where the failure is stored, so once per cache window. Never the secret, never a person. */
    private function unknown(string $why): null
    {
        Log::warning('InfoDot entitlement check gave no answer; carrying on.', ['platform' => $this->platform(), 'reason' => $why]);

        return null;
    }
}
