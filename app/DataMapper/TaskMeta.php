<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\DataMapper;

use App\Casts\TaskMetaCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
class TaskMeta implements Arrayable, Castable, JsonSerializable
{
    public function __construct(
        public string $calendar_event_id = '',
        public string $clockify_key = '',
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public static function castUsing(array $arguments): string
    {
        return TaskMetaCast::class;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            calendar_event_id: (string) ($data['calendar_event_id'] ?? $data['provider_event_id'] ?? $data['event_id'] ?? ''),
            clockify_key: (string) ($data['clockify_key'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = ['calendar_event_id' => $this->calendar_event_id];

        if ($this->clockify_key !== '') {
            $meta['clockify_key'] = $this->clockify_key;
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isEmpty(): bool
    {
        return $this->calendar_event_id === '' && $this->clockify_key === '';
    }
}
