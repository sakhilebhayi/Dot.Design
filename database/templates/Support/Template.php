<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/** Assembles one template record: metadata, pages, auto-derived slots. */
final class Template
{
    /** @var array<int,array<string,mixed>> */
    private array $pages = [];

    private ?Palette $pal = null;

    private ?TypePair $type = null;

    public readonly float $w;

    public readonly float $h;

    /** @param array<string,mixed> $meta */
    private function __construct(private array $meta, private ?float $margin)
    {
        [$w, $h] = Formats::size($meta['format_key']);
        $this->w = $w;
        $this->h = $h;
    }

    /**
     * @param array{slot_labels?:array<string,string>,slug:string,name:string,description:string,category:string,kind:string,industries:array,purposes:array,styles:array,platforms:array,format_key:string,popularity?:int,margin?:float} $meta
     */
    public static function start(array $meta): self
    {
        $margin = $meta['margin'] ?? null;
        $labels = $meta['slot_labels'] ?? [];
        unset($meta['margin'], $meta['slot_labels']);
        $meta['__labels'] = $labels;
        $meta += ['objectives' => ['awareness'], 'status' => 'published'];
        Vocab::check($meta);

        return new self($meta, $margin);
    }

    public function palette(Palette $p): self
    {
        $this->pal = $p;

        return $this;
    }

    public function type(TypePair $t): self
    {
        $this->type = $t;

        return $this;
    }

    /** A new page on the template canvas (or a custom size). */
    public function page(?float $w = null, ?float $h = null, ?float $margin = null): Page
    {
        $idx = count($this->pages) + 1;
        $page = Page::make($w ?? $this->w, $h ?? $this->h, $this->pal, $this->type, $margin ?? $this->margin, 'p'.$idx.'e');
        $this->pages[] = ['page' => $page, 'name' => null];

        return $page;
    }

    public function name(Page $page, string $name): void
    {
        foreach ($this->pages as &$p) {
            if ($p['page'] === $page) {
                $p['name'] = $name;
            }
        }
    }

    public function done(): array
    {
        $pages = [];
        foreach ($this->pages as $i => $p) {
            $pages[] = $p['page']->toArray($p['name'] ?? 'Page '.($i + 1));
        }
        $slots = [];
        $walk = function (array $els) use (&$walk, &$slots): void {
            foreach ($els as $e) {
                if (isset($e['bind']) && ! isset($slots[$e['bind']])) {
                    $key = $e['bind'];
                    $isImage = in_array($key, Sample::IMAGE_SLOTS, true);
                    $slots[$key] = [
                        'key' => $key, 'label' => $this->meta['__labels'][$key] ?? Sample::SLOT_LABELS[$key] ?? $key, 'type' => $isImage ? 'image' : 'text',
                        'help' => Sample::SLOT_HELP[$key] ?? '',
                    ] + ($isImage ? [] : ['default' => $e['text'] ?? '']);
                }
                if (isset($e['children'])) {
                    $walk($e['children']);
                }
            }
        };
        foreach ($pages as $p) {
            $walk($p['elements']);
        }

        $meta = $this->meta;
        unset($meta['__labels']);

        return $meta + [
            'width' => $this->w, 'height' => $this->h, 'pages' => $pages, 'slots' => array_values($slots),
        ] + ['popularity' => 50];
    }
}
