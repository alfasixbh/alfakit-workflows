<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use Closure;
use Illuminate\Support\Str;

/**
 * A state of a workflow: its stored value (`name`), label, badge colour and icon.
 *
 * ```php
 * State::make('approved')->label('Approved')->color('success')->icon('badge-check')
 * ```
 */
class State
{
    use EvaluatesClosures;

    protected string|Closure|null $label = null;

    protected string|Closure|null $color = null;

    protected string|Closure|null $icon = null;

    protected string|Closure|null $description = null;

    protected bool $isFinal = false;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return is_string($label) && $label !== '' ? $label : Str::headline($this->name);
    }

    /**
     * A semantic colour: `primary`, `success`, `warning`, `danger`, `info`, `gray` (default).
     */
    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): string
    {
        $color = $this->evaluate($this->color);

        return is_string($color) && $color !== '' ? $color : 'gray';
    }

    public function icon(string|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): ?string
    {
        $icon = $this->evaluate($this->icon);

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * A state no transition leaves (the definition is refused if one does).
     */
    public function final(bool $condition = true): static
    {
        $this->isFinal = $condition;

        return $this;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }
}
