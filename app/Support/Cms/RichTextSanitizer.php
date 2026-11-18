<?php

namespace App\Support\Cms;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Validation\ValidationException;

class RichTextSanitizer
{
    private const array TAGS = ['p', 'h2', 'h3', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'br', 'a'];

    private const array DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'video', 'audio', 'img', 'picture', 'link', 'meta', 'base', 'noscript'];

    public function clean(string $html): string
    {
        if (strlen($html) > 200000) {
            throw ValidationException::withMessages(['body' => 'Treść jest zbyt długa.']);
        }
        $document = HTMLDocument::createFromString('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $nodes = 0;
        $output = '';
        foreach ($document->body->childNodes as $node) {
            $output .= $this->render($node, 0, $nodes);
        }

        return trim($output);
    }

    public function text(string $html): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function render(Node $node, int $depth, int &$nodes): string
    {
        if (++$nodes > 10000 || $depth > 60) {
            throw ValidationException::withMessages(['body' => 'Treść ma zbyt złożone formatowanie. Wklej ją jako zwykły tekst.']);
        }
        if ($node instanceof Text) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof Element || $node->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
            return '';
        }
        $tag = strtolower($node->localName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= $this->render($child, $depth + 1, $nodes);
        }
        $tag = match ($tag) {
            'b' => 'strong', 'i' => 'em', default => $tag
        };
        if (! in_array($tag, self::TAGS, true)) {
            return $children.($tag === 'div' ? '<br>' : '');
        }
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'a') {
            $href = $this->safeHref($node->getAttribute('href'));

            return $href === null ? $children : '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').'" rel="noopener noreferrer">'.$children.'</a>';
        }

        return '<'.$tag.'>'.$children.'</'.$tag.'>';
    }

    private function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $href)) {
            return null;
        }
        if (preg_match('~^/(?!/)[a-zA-Z0-9/_.?\#&=%+\~-]*$~D', $href)) {
            return $href;
        }
        if (filter_var($href, FILTER_VALIDATE_URL) && parse_url($href, PHP_URL_SCHEME) === 'https'
            && parse_url($href, PHP_URL_USER) === null && parse_url($href, PHP_URL_PASS) === null) {
            return $href;
        }

        return null;
    }
}
