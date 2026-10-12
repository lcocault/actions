<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Portal\View;
use PHPUnit\Framework\TestCase;

/** Garde d'échappement : toute sortie d'un gabarit passe par `$e(...)` ou `$url(...)` (ou un partiel/contenu de mise en page). */
final class PortalViewsTest extends TestCase
{
    public function testEveryTemplateOutputIsEscaped(): void
    {
        $files = glob(__DIR__ . '/../../src/Portal/Views/*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertSame([], self::violations($source, basename($file) === 'layout.php'), basename($file));
        }
    }

    public function testGuardDetectsUnsafeOutput(): void
    {
        foreach ([
            '<?= $title ?>',
            '<?= $e($a) . $b ?>',
            '<?= $e($a) ?><?= $b ?>',
            '<?php echo $a; ?>',
            '<?php print($a); ?>',
            '<?= htmlspecialchars($a) ?>',
            '<?= $content ?>', // autorisé seulement dans la mise en page
            '<?= $e("(") . $x ?>',
            '<?= $e($a) ?><?= $e($b) ?><?= $raw ?>',
        ] as $unsafe) {
            self::assertNotSame([], self::violations($unsafe, false), $unsafe);
        }
        foreach ([
            '<?= $e($a) ?>',
            '<?= $url(\'/tasks/\' . $id, [\'a\' => 1]) ?>',
            '<?= $render(\'x\', [\'t\' => $t]) ?>',
            '<?= $e($a ? \' (x)\' : \'\') ?>',
            '<?php foreach ($a as $b): ?><?= $e($b) ?><?php endforeach; ?>',
        ] as $safe) {
            self::assertSame([], self::violations($safe, false), $safe);
        }
        self::assertSame([], self::violations('<?= $content ?>', true));
    }

    public function testEscapeAssistant(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt; &quot;x&quot; &#039;y&#039; &amp;', View::escape('<script>alert(1)</script> "x" \'y\' &'));
        self::assertSame('', View::escape(null));
        self::assertSame('12', View::escape(12));
        self::assertSame('&#039; onmouseover=&#039;x', View::escape("' onmouseover='x"));
    }

    public function testEscapeRefusesArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        View::escape(['x']);
    }

    /** @return list<string> */
    private static function violations(string $source, bool $isLayout): array
    {
        $found = [];
        if (preg_match('/\b(echo|print|printf|print_r|var_dump|var_export)\b/', $source) === 1) {
            $found[] = 'sortie directe interdite';
        }
        if (preg_match('/<\?(?!php|=)/', $source) === 1 || str_contains($source, '<%')) {
            $found[] = 'balise inattendue';
        }
        preg_match_all('/<\?=(.*?)\?>/s', $source, $matches);
        foreach ($matches[1] as $expression) {
            $expression = trim($expression);
            if ($isLayout && $expression === '$content') {
                continue;
            }
            if (preg_match('/^\$(e|url|render)\(/', $expression) !== 1 || self::closingParenthesis($expression) !== strlen($expression) - 1) {
                $found[] = $expression;
            }
        }

        return $found;
    }

    /** Position de la parenthèse fermant le premier appel (chaînes entre quotes ignorées), ou -1. */
    private static function closingParenthesis(string $s): int
    {
        $start = strpos($s, '(');
        $depth = 0;
        $quote = null;
        for ($i = $start; $i < strlen($s); $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '\'' || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $i;
            }
        }

        return -1;
    }
}
