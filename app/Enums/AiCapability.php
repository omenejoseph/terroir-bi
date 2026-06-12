<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A model capability the platform configures and health-checks independently
 * from the back office. The data-entry features only need `text` and `vision`;
 * the others are configured/tested for future use.
 */
enum AiCapability: string
{
    case Text = 'text';
    case Vision = 'vision';
    case ImageGeneration = 'image_generation';
    case AudioGeneration = 'audio_generation';
    case AudioTranscription = 'audio_transcription';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Vision => 'Image understanding (vision)',
            self::ImageGeneration => 'Image generation',
            self::AudioGeneration => 'Audio generation (speech)',
            self::AudioTranscription => 'Audio transcription / translation',
        };
    }

    /** The global_settings key holding this capability's provider+model. */
    public function settingKey(): string
    {
        return 'ai.model.'.$this->value;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
