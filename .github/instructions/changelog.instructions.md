---
applyTo: '**'
description: 'Changelog, readme and version management rules for every code change'
---

# Changelog & Version Management

## 1) Changelog in readme.txt

Nach **jeder** Codeänderung den `== Changelog ==`-Abschnitt in `slytranslate/readme.txt` aktualisieren.

### Wurde eine Versionsnummer genannt?

**Ja** – Eintrag direkt unter der genannten Version anlegen (ggf. Versionsblock `= X.Y.Z =` neu erstellen, falls noch nicht vorhanden):

```
= X.Y.Z =
* <Änderung>
```

**Nein** – Eintrag in einem Sammelblock `= Unreleased =` am Anfang des Changelogs ablegen:

```
= Unreleased =
* <Änderung>
```

Der `= Unreleased =`-Block wird beim nächsten Release durch den echten Versionsblock ersetzt.

## 2) readme.txt und README.md synchron halten

Wenn Features **hinzugefügt, entfernt oder geändert** werden:

- `slytranslate/readme.txt` — Abschnitte `== Description ==`, `== Frequently Asked Questions ==` und Ability-Liste anpassen.
- `README.md` (Root) — entsprechende Abschnitte spiegeln.

Beide Dateien müssen inhaltlich konsistent bleiben. Keine Datei ohne die andere aktualisieren.

## 3) Versionsnummer eintragen

Wird explizit eine Versionsnummer angegeben (z. B. `1.4.0`), **müssen** folgende drei Stellen synchron aktualisiert werden:

| Datei | Feld |
|---|---|
| `slytranslate/readme.txt` | `Stable tag: X.Y.Z` |
| `slytranslate/ai-translate.php` | Plugin-Header `Version: X.Y.Z` |
| `slytranslate/ai-translate.php` | Klassenkonstante `private const VERSION = 'X.Y.Z';` |

Außerdem den `= Unreleased =`-Block (falls vorhanden) in `= X.Y.Z =` umbenennen.
