# code-thresholds

> Ein abhängigkeitsfreies Größen- und Komplexitäts-Gate für PHP, das nur die geänderten Dateien prüft: Methodenanzahl, Parameter, Returns, Funktionslänge, Verschachtelungstiefe und zwei Defekt-Checks, im Pre-Commit-Hook oder im Pull Request.

[![CI](https://github.com/Stefan39/code-thresholds/actions/workflows/ci.yml/badge.svg)](https://github.com/Stefan39/code-thresholds/actions/workflows/ci.yml)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![Latest Stable Version](https://img.shields.io/packagist/v/stejac/code-thresholds.svg)](https://packagist.org/packages/stejac/code-thresholds)
[![codecov](https://codecov.io/gh/Stefan39/code-thresholds/branch/main/graph/badge.svg)](https://codecov.io/gh/Stefan39/code-thresholds)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

[English version](README.md)

## Warum dieses Tool?

Größen- und Komplexitätsgrenzen werden meist spät geprüft: Eine gehostete Analyse meldet sie nach dem Push, und die Korrektur wird zur nächsten Review-Runde. Dieses Tool verlegt genau die Grenzen, die sich aus einer einzelnen Datei zählen lassen, vor den Commit.

| Tool | Stärke | Unterschied zu code-thresholds |
|---|---|---|
| [PHPStan](https://phpstan.org) | Typen und Bugs | Hat keine Größen- oder Komplexitätsgrenzen; dieses Tool schaut gar nicht auf Typen. Am besten beide nutzen. |
| [PHPMD](https://phpmd.org) | Ähnliche Metriken (Methodenanzahl, Parameterliste, ...) | PHPMD bringt eigene Abhängigkeiten und Regelsatz-XML mit. Dieses Tool ist ein `composer require --dev` mit `ext-tokenizer` als einziger Voraussetzung und einer kleinen PHP-Konfigurationsdatei. |
| [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) | Code-Stil, dazu einige Metrik-Sniffs | Stil ist hier nicht das Ziel, und es gibt kein Regelwerk zusammenzustellen. |
| [SonarCloud](https://www.sonarsource.com/products/sonarcloud/) | Cognitive Complexity, Duplikate, Security Hotspots, Historie | Läuft serverseitig nach dem Push. Dieses Tool antwortet lokal, im Pre-Commit-Hook oder im Pull-Request-Job, bevor ein Finding entsteht. |

Der Unterschied liegt in der Kombination: **keine Abhängigkeiten**, **nur geänderte Dateien** (gestaged oder per Diff gegen eine Basis-Referenz), damit es schnell genug für jeden Commit bleibt, und **eine Konfigurationsdatei**, die schlichtes PHP ist.

### Was bewusst fehlt

Ein Check, der etwas meldet, das ein Projekt gar nicht durchsetzt, ist schlechter als kein Check: Er blockiert Commits und wird abgeschaltet. Drei Checks fehlen deshalb absichtlich.

- **Cognitive Complexity.** Eine Token-basierte Nachbildung, verglichen mit Referenzwerten, lag bei einer Methode zu hoch und bei einer anderen zu niedrig. Zu niedrige Werte sind die gefährliche Richtung, weil sie falsche Sicherheit geben. Das bleibt beim Reviewer oder einem vollständigen Analyse-Tool.
- **Doppelte Literale.** Das Zählen markiert gewöhnliche Array-Schlüssel wie `'id'` oder `'value'`: Rauschen statt Signal.
- **Copy-Paste-Erkennung.** Sie braucht einen für Duplikate gebauten Tokenizer; eine lokale Nachahmung übersieht Duplikate oder schlägt Fehlalarm. Dafür gibt es spezialisierte Tools wie jscpd oder PHP Copy/Paste Detector.

Gemessen wird ausschließlich auf `token_get_all()`, nie mit regulären Ausdrücken. Ein Regex über String-Literale hält die Lücke zwischen zwei Array-Schlüsseln für ein eigenes Literal.

## Schnellstart

```bash
composer require --dev stejac/code-thresholds
```

```bash
vendor/bin/code-thresholds                 # in Git gestagte PHP-Dateien
vendor/bin/code-thresholds --base=origin/main   # gegen eine Referenz geänderte Dateien
vendor/bin/code-thresholds --all           # alles unterhalb von source_dirs
vendor/bin/code-thresholds src/Foo.php lib # explizite Dateien oder Verzeichnisse
```

Ohne Konfigurationsdatei gelten die Standardwerte, Quellverzeichnis ist `src/`. Die Erfolgsmeldung geht nach stdout, die Liste der Verstöße nach stderr:

```text
code-thresholds: limits exceeded:

  src/Service/Importer.php:42
    parameters — import() is 9 (max 7)
  src/Service/Importer.php:57
    unused variable — $legacy in import()

2 violation(s).
```

Exit-Codes: `0` keine Verstöße, `1` Verstöße gefunden, `2` Aufruf- oder Konfigurationsfehler.

Fertige Integrationen:

- Pre-Commit-Hook: [`docs/examples/pre-commit`](docs/examples/pre-commit)
- GitHub Actions bei Pull Requests: [`docs/examples/github-action.yml`](docs/examples/github-action.yml)
- Vollständig kommentierte Konfigurationsdatei: [`docs/examples/code-thresholds.php`](docs/examples/code-thresholds.php)

## Funktionen

| Check | Konfig-Schlüssel | Standard | Gezählt wird |
|---|---|---|---|
| Methoden pro Klasse | `limits.methods_per_class` | 20 | Methoden einer Klasse, eines Interfaces, Traits oder Enums. Closures sind keine Methoden. |
| Parameter | `limits.parameters` | 7 | Parameter einer Funktion oder Methode. Ein abschließendes Komma sowie Kommas in Default-Werten oder Attributen zählen nicht. |
| Returns | `limits.returns` | 3 | `return`-Anweisungen einer Funktion. Returns in einer verschachtelten Closure gehören zur Closure. |
| Funktionslänge | `limits.function_length` | 100 | Zeilen des Funktionskörpers. |
| Verschachtelungstiefe | `limits.nesting_depth` | 4 | Verschachtelte `if`-, `for`-, `foreach`-, `while`-, `switch`-, `try`- und `match`-Blöcke. Zehn aufeinanderfolgende `if`-Blöcke sind Tiefe eins. |
| Unbenutzte Variable | `rules.unused_variable` | an | Eine Variable, die genau einmal im Funktionskörper vorkommt, und zwar als Zuweisung. Bewusst konservativ, um Fehlalarme bei Destructuring, Referenzen und Interpolation zu vermeiden. |
| Schleifenzähler neu zugewiesen | `rules.loop_counter_reassigned` | an | Ein `for`-Zähler, dem im Schleifenkörper ein Wert zugewiesen wird. Besser `while` nutzen. |

## Konfiguration

Lege `code-thresholds.php` in dem Verzeichnis an, aus dem du das Tool startest (oder übergib `--config=<file>`). Die Datei gibt ein Array zurück; jeder Schlüssel ist optional. Ohne Datei gelten die Standardwerte unten. Unbekannte Schlüssel und falsche Typen beenden den Lauf mit Exit-Code 2.

```php
<?php

return [
    'source_dirs' => ['src', 'lib'],
    'exclude' => ['src/Generated/*'],
    'limits' => ['parameters' => 5, 'function_length' => null], // null schaltet eine Regel ab
    'rules' => ['loop_counter_reassigned' => false],
];
```

| Schlüssel | Typ | Standard | Bedeutung |
|---|---|---|---|
| `source_dirs` | `list<string>` | `['src']` | Von `--all` durchsuchte Verzeichnisse. Fehlende Verzeichnisse werden übersprungen; existiert keines, endet der Lauf mit Exit-Code 2. |
| `exclude` | `list<string>` | `[]` | `fnmatch()`-Muster für Dateien, die nie geprüft werden. `*` passt auch auf `/`. |
| `limits` | `array<string, int\|null>` | siehe Funktionen | Schlüssel `methods_per_class`, `parameters`, `returns`, `function_length`, `nesting_depth`. `null` schaltet ein Limit ab. |
| `rules` | `array<string, bool>` | alle `true` | Schlüssel `unused_variable`, `loop_counter_reassigned`. |
| `methods_per_class_exclude` | `list<string>` | `['*/Entity/*', 'tests/*']` | Muster für Dateien, die nur von der Methodenanzahl ausgenommen sind. Entities folgen der Tabellenbreite, und eine Testklasse hat eine Methode pro Szenario. |
| `exempt_constructors` | `bool` | `true` | Konstruktoren werden nicht gegen das Parameter-Limit geprüft: Acht injizierte Abhängigkeiten sind nicht derselbe Mangel wie ein Aufruf mit acht Argumenten. |

Das Projektverzeichnis ist das Verzeichnis der Konfigurationsdatei, ohne Datei das Arbeitsverzeichnis. Muster und `source_dirs` beziehen sich darauf. Der Abgleich ist bewusst daran verankert: Ein Checkout unterhalb eines Verzeichnisses namens `tests` darf nicht das gesamte `src/` ausnehmen.

Nicht vorhandene Pfade, Dateien ohne Endung `.php` und ausgeschlossene Dateien werden ignoriert.

## Sicherheit

Das Tool liest die PHP-Dateien, auf die es zeigt, und führt sie nie aus. Ausnahme ist die Konfigurationsdatei: Sie ist PHP und wird eingebunden. Behandle sie wie die Skripte in `composer.json` und starte das Tool nur in Projekten, denen du vertraust. Git wird ohne Shell gestartet, und die `--base`-Referenz wird gegen ein enges Muster geprüft, sodass über sie weder Optionen noch Befehle eingeschleust werden können. Schwachstellen melden: siehe [SECURITY.md](SECURITY.md).

## Voraussetzungen

PHP 8.3 oder neuer mit `ext-tokenizer`. `git` für den Staged- und den `--base`-Modus. Entwickelt und getestet unter Linux und macOS.

## Mitmachen

Issues und Pull Requests sind willkommen, siehe [CONTRIBUTING.md](CONTRIBUTING.md). Die lokal nötigen Checks:

```bash
composer test         # PHPUnit
composer phpstan      # PHPStan, höchste Stufe
composer cs           # php-cs-fixer (Trockenlauf)
composer thresholds   # das Paket prüft sein eigenes src/ mit sich selbst
```

## Über

Entwickelt und gepflegt von [Stefan Jacomeit](https://www.jacomeit.de).

## Lizenz

MIT, siehe [LICENSE](LICENSE).
