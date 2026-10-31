<?php

namespace NexaPressMail\Services\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

final class MailHtmlSanitizer
{
    public function sanitize(string $html): string
    {
        if (trim($html) === '' || !class_exists(DOMDocument::class)) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        $config = require NEXAPRESS_MAIL_PATH . '/config/security.php';
        $allowed = array_flip($config['allowed_html']);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="npm-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('npm-root');
        if (!$root) { return ''; }
        $this->clean($root, $allowed);
        $output = '';
        foreach ($root->childNodes as $child) { $output .= $dom->saveHTML($child); }
        return $output;
    }

    private function clean(DOMNode $node, array $allowed, int $depth = 0): void
    {
        for ($child = $node->firstChild; $child;) {
            $next = $child->nextSibling;
            if ($child instanceof DOMElement) {
                if ($depth >= 64) {
                    $node->removeChild($child);
                    $child = $next; continue;
                }
                $tag = strtolower($child->tagName);
                if (!isset($allowed[$tag])) {
                    if (in_array($tag, ['script','style','iframe','object','embed','form','svg','math'], true)) {
                        $node->removeChild($child);
                        $child = $next; continue;
                    }
                    // Clean descendants before unwrapping: the sibling cursor skips moved nodes.
                    $this->clean($child, $allowed, $depth + 1);
                    while ($child->firstChild) { $node->insertBefore($child->firstChild, $child); }
                    $node->removeChild($child); $child = $next; continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    if (!in_array($name, ['href','src','alt','title','width','height'], true)
                        || (($name === 'href' || $name === 'src') && !UrlSchemeValidator::safe($attribute->value, $name === 'src'))) {
                        $child->removeAttribute($attribute->name);
                    }
                }
                if ($tag === 'a') { $child->setAttribute('rel', 'noopener noreferrer'); $child->setAttribute('target', '_blank'); }
                $this->clean($child, $allowed, $depth + 1);
            } elseif (!$child instanceof DOMText) {
                $node->removeChild($child);
            }
            $child = $next;
        }
    }
}
