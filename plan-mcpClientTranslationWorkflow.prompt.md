## Plan: MCP-Client-Übersetzungsworkflow

TL;DR: Ergänze neben dem bestehenden serverseitigen Connector-Workflow einen expliziten MCP-Client-Workflow mit neuen Prepare/Apply-Abilities. WordPress liefert strukturierte Übersetzungseinheiten und speichert fertige Übersetzungen über die bestehenden Adapter; der MCP-Client nutzt sein eigenes LLM. Sichtbare SlyTranslate-Übersetzungs-UI bleibt dagegen nur verfügbar, wenn der serverseitige WordPress-AI-Client/Connector-Transport verfügbar ist.

**Entscheidungen**
- Architektur: neue MCP-Tools statt `translate-content` zu überladen.
- Umfang: Einzelbeitrag und Bulk im ersten Wurf.
- UI: alle sichtbaren SlyTranslate-Übersetzungsoberflächen ohne serverseitigen Connector ausblenden; REST/MCP-Abilities bleiben registriert.
- Kein Umbau von `TranslationRuntime` zum generischen Transport-Layer in diesem Schritt.

**Steps**
1. Zentrale Verfügbarkeitshelfer definieren
   - In `/Users/timon/homelab/slytranslate/slytranslate/slytranslate.php` oder einer kleinen Service-Klasse einen Helfer für serverseitige Übersetzungs-UI einführen, z. B. `AI_Translate::is_server_translation_ui_available()`.
   - Empfohlener erster Gate: `function_exists( 'wp_ai_client_prompt' )`; optional per Filter erweiterbar, falls später echte Connector-Konfiguration statt AI-Client-Funktion geprüft werden soll.
   - Bestehende Abilities/REST-Routen nicht von diesem Gate abhängig machen.
2. UI-Gating auf Connector-Verfügbarkeit ziehen
   - In `EditorBootstrap::enqueue_editor_plugin()` oder Bootstrap-Daten verhindern, dass Sidebar, Block-Translate und Selection-Translate ohne Connector sichtbar werden.
   - In `ListTableTranslation` Row Actions, Bulk Action, Dialog-Assets und Background-Bar an denselben Gate hängen.
   - In `TranslatePressEditorIntegration::is_supported_context()` zusätzlich Connector-Verfügbarkeit verlangen.
   - Settings/Configuration und MCP/REST bleiben erreichbar, damit ein MCP-Client und Admin-Konfiguration nicht durch UI-Gating blockiert werden.
3. Neues Service-Modul für Client-Workflow erstellen
   - Neue Klasse `/Users/timon/homelab/slytranslate/slytranslate/inc/ClientTranslationWorkflowService.php`.
   - Verantwortlich für `prepare_single`, `apply_single`, `prepare_bulk`, `apply_bulk`.
   - Wiederverwenden: `AI_Translate::get_adapter()`, `TranslationQueryService::validate_translatable_post_type()`, `TranslationQueryService::get_existing_translation_id()`, `TranslationQueryService::resolve_bulk_source_post_ids()`, `PostTranslationService::normalize_post_status()` und `TranslationPluginAdapter::create_translation()`.
4. Prepare-Workflow modellieren
   - Single-Prepare validiert Post, Capability, Sprachplugin, Target-Language, Source-Language und Existing-Translation/Overwrite wie der aktuelle serverseitige Flow.
   - Ausgabe ist ein Job-Paket mit `source_post_id`, `source_language`, `target_language`, `single_entry_mode`, `overwrite`, `post_status`, `source_hash`, `existing_translation` und übersetzbaren Units.
   - Units enthalten stabile IDs, Feldtyp (`title`, `content`, `excerpt`, `meta`, `string_table_segment`), Quelltext, Format-Hinweis und bei TranslatePress `lookup_keys`.
   - Für WP Multilang/WPGlobus wird der Source-Variant zuerst über `get_language_variant()` extrahiert; für Polylang bleibt die Post-Sprache maßgeblich; für TranslatePress bleibt Source-Content im Ursprungspost.
5. Apply-Workflow sicher machen
   - Apply revalidiert Post, Capabilities, Adapter, Sprachen, Overwrite und vorhandene Translation.
   - Apply rekonstruiert die Prepare-Planung serverseitig und vergleicht `source_hash`, sofern `allow_stale_source` nicht explizit gesetzt ist.
   - Apply akzeptiert nur Übersetzungen für bekannte Unit-IDs aus dem aktuellen Plan; unbekannte IDs werden abgelehnt.
   - Für generische Adapter wird daraus `post_title`, `post_content`, `post_excerpt`, `meta`; für TranslatePress zusätzlich bzw. statt `post_content` `content_string_pairs` anhand der Lookup-Keys.
   - Persistenz läuft ausschließlich über `adapter->create_translation()`; dadurch bleiben Polylang-Links/Taxonomie, WP Multilang `_languages`, WPGlobus Inline-Markup und TranslatePress-Dictionary-Persistenz erhalten.
6. Meta-Unterstützung extrahieren
   - `MetaTranslationService` um nicht-LLM-Helfer erweitern: Meta-Plan bauen, Clear-Keys anwenden, translated Meta anhand stabiler Unit-IDs in den `create_translation()`-Payload mergen.
   - Keine neuen Meta-Keys vom Client ungeprüft akzeptieren.
   - Array-/SEO-Meta nur in dem Umfang unterstützen, den die bestehenden Meta-Helfer sicher serialisieren/rekonstruieren können; sonst als nicht übersetzbare Kopie behandeln.
7. Content-Units pragmatisch und sicher aufbauen
   - Für TranslatePress vorhandenes `StringTableContentAdapter::build_content_translation_units()` nutzen.
   - Für andere Adapter im ersten Schritt mindestens Feld-Units für Titel, Content und Excerpt bereitstellen, mit klarer Preserve-Markup-Anweisung in der Ability-Beschreibung.
   - Wenn robuste Block-Chunk-Rekonstruktion gewünscht ist, aus `ContentTranslator` eine separate Unit-Plan/Reconstruction-Schicht extrahieren; das wäre ein zweiter, größerer Ausbau und nicht Teil des initialen Minimalpfads.
8. Abilities und REST-Brücke registrieren
   - Neue Abilities: `ai-translate/prepare-client-translation`, `ai-translate/apply-client-translation`, `ai-translate/prepare-client-translation-bulk`, `ai-translate/apply-client-translation-bulk`.
   - Prepare-Abilities bekommen `readonly` MCP-Meta; Apply-Abilities bleiben mutierende Tools.
   - REST-Routen analog zu bestehenden `/ai-translate/.../run` Routen ergänzen.
   - Beschreibungen so formulieren, dass MCP-Clients klar zwischen serverseitigem Connector-Tool (`translate-content`) und clientseitigem LLM-Workflow (Prepare/Apply) unterscheiden.
9. Bulk-Verhalten definieren
   - Bulk-Prepare nutzt bestehende `post_ids`/`post_type`/`limit`-Auflösung und gibt eine Liste von Job-Paketen zurück; Limit bleibt maximal 50, optional mit Zeichenbudget-Cap, damit MCP-Payloads nicht explodieren.
   - Bulk-Apply verarbeitet Jobs sequenziell, gibt pro Item `success`, `skipped` oder `failed` zurück und stoppt nicht beim ersten Fehler.
   - Für sehr große Inhalte kann ein Job mit `too_large_for_bulk_prepare` markiert werden, sodass der MCP-Client ihn einzeln vorbereiten kann.
10. Bestehende serverseitige Runtime bewusst unverändert lassen
   - `translate-text`, `translate-blocks`, `translate-content` und `translate-content-bulk` bleiben Connector-basierte Server-LLM-Tools.
   - Ohne Connector dürfen diese weiterhin registriert sein, aber sie liefern beim Ausführen den vorhandenen Transportfehler; neue Client-Tools funktionieren ohne `wp_ai_client_prompt()`.
11. Dokumentation und Changelog aktualisieren
   - `CHANGELOG.md` und `/Users/timon/homelab/slytranslate/slytranslate/changelog.txt` unter der aktuellen Basisversion ergänzen.
   - Wegen neuem Workflow `README.md` und `/Users/timon/homelab/slytranslate/slytranslate/readme.txt` synchron aktualisieren: MCP-Client-Workflow, Unterschied serverseitiger Connector vs. clientseitiges LLM, Tool-Reihenfolge und Sicherheitsgrenzen.

**Relevant files**
- `/Users/timon/homelab/slytranslate/slytranslate/slytranslate.php` — Hooks, REST-Routen, Execute-Callbacks, zentraler UI-Connector-Gate.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/AbilityRegistrar.php` — neue MCP-Ability-Schemas und Beschreibungen.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/ClientTranslationWorkflowService.php` — neue Orchestrierung für Prepare/Apply.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/PostTranslationService.php` — vorhandene Source-/Persistenzlogik als Referenz; ggf. kleine Helfer extrahieren.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/MetaTranslationService.php` — Meta-Planung ohne LLM-Aufrufe extrahieren.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/TranslationQueryService.php` — Bulk-Post-Auflösung, Existing-Translation und Post-Type-Validierung wiederverwenden.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/EditorBootstrap.php` — Gutenberg-UI-Gating und Bootstrap-Flag.
- `/Users/timon/homelab/slytranslate/slytranslate/assets/editor-plugin.js` — defensives Gating für Sidebar, Block-Translate, Selection-Translate.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/ListTableTranslation.php` — Row/Bulk Actions und Assets nur mit Connector.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/TranslatePressEditorIntegration.php` — Visual-Editor-Panel nur mit Connector.
- `/Users/timon/homelab/slytranslate/slytranslate/inc/TranslatePressAdapter.php` — `content_string_pairs`-Persistenz für Client-Workflow nutzen.
- `/Users/timon/homelab/slytranslate/slytranslate/tests/Unit/AbilityRegistrationTest.php` — neue Ability-Verträge.
- `/Users/timon/homelab/slytranslate/slytranslate/tests/Unit/EditorRestRouteRegistrationTest.php` — neue REST-Routen.
- `/Users/timon/homelab/slytranslate/slytranslate/tests/Unit/AbilityInputValidationTest.php` — neue Input-/Fehlerfälle.
- `/Users/timon/homelab/slytranslate/slytranslate/tests/Unit/ClientTranslationWorkflowServiceTest.php` — neue Kernabdeckung.
- `/Users/timon/homelab/slytranslate/slytranslate/tests/Unit/ListTableKeepaliveRenderTest.php` und `/Users/timon/homelab/slytranslate/slytranslate/tests/Unit/TranslatePressEditorIntegrationTest.php` — UI-Gating-Abdeckung.

**Verification**
1. Focused PHPUnit: `cd slytranslate && ./vendor/bin/phpunit --filter 'AbilityRegistrationTest|EditorRestRouteRegistrationTest|AbilityInputValidationTest|ClientTranslationWorkflowServiceTest|ListTableKeepaliveRenderTest|TranslatePressEditorIntegrationTest'`.
2. Adapter-focused PHPUnit für Persistenzpfade: `cd slytranslate && ./vendor/bin/phpunit --filter 'PolylangAdapterTest|WpMultilangAdapterTest|WpglobusAdapterTest|TranslatePressAdapterTest|TranslationQueryServiceTest|ListTableTranslationTest'`.
3. Vollständige lokale Suite, wenn die fokussierten Tests grün sind: `cd slytranslate && ./vendor/bin/phpunit`.
4. Manuelle/Unit-Prüfung ohne `wp_ai_client_prompt()`: neue Prepare/Apply-Abilities registriert und ausführbar; serverseitige UI nicht sichtbar; bestehende serverseitige Translate-Abilities bleiben registriert.
5. Manuelle/Unit-Prüfung mit gemocktem `wp_ai_client_prompt()`: Editor-/List-Table-/TranslatePress-UI erscheint wie bisher.
6. Build/Completion nach Repo-Regel: `WP Plugin: Build and Verify Plugin ZIP`, ggf. Sprachdateien mitstagen, genau ein Commit via git-commit Skill, danach passende Build-and-Deploy-Tasks.
7. MCP-Smoke nach Deploy: für jeden betroffenen Adapter Prepare → Client-LLM übersetzt → Apply; zusätzlich bestehende serverseitige `translate-content` Smokes mit den vorgeschriebenen Fixture-Posts/Parametern, sofern der serverseitige Pfad oder gemeinsame Ability-Verträge berührt wurden.

**Scope boundaries**
- In Scope: neue MCP-Client-Tools, Bulk-Orchestrierung, UI-Gating, Adapter-Persistenz über bestehende `create_translation()`-Verträge, Docs/Changelog.
- Out of Scope für den initialen Wurf: generische LLM-Transport-Abstraktion in `TranslationRuntime`, eigener Provider/Connector-Ersatz, vollwertige UI für den Client-LLM-Workflow, tiefes Block-Chunk-Reconstruction-Refactoring jenseits der minimal sicheren Content-Units.

**Further Considerations**
1. Späterer Ausbau: robuste Gutenberg-Block-Unit-Rekonstruktion aus `ContentTranslator` extrahieren, falls große Posts im MCP-Client-Workflow häufig an Token-/Markup-Grenzen stoßen.
2. Optionaler strengerer UI-Gate: statt nur `wp_ai_client_prompt()` auch erfolgreiche Modelldiscovery verlangen; das kann aber bei temporären Provider-Metadatenproblemen unnötig UI verstecken.
