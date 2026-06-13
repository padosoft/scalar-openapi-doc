# Piano di Implementazione — Portale API Docs (scalar-openapi-doc)

## Context

Repo `C:\xampp\htdocs\scalar-openapi-doc` (vuoto: solo README+LICENSE, remote `padosoft/scalar-openapi-doc`, branch `main`). Obiettivo: app **Laravel 13 + PHP 8.5 (Herd)** che renderizza documentazione OpenAPI via **Scalar**, aggiungendo ciò che Scalar non offre: **login (Fortify)**, **ruoli admin/user (spatie/laravel-permission)** e **filtraggio server-side per-utente della spec** (l'utente vede solo tag/endpoint concessi, semantica UNION; admin vede tutto). Spec esterna fetchata con cache Redis + fallback stale-on-error. Admin: CRUD utenti con grant anti-tampering, server playground iniettati in `servers`, audit log auth, svuota cache.

Fonti: spec `C:\Users\lopad\Downloads\doc-api-plan\SPEC_Portale_API_Docs.md` + skeleton PHP pronti (OpenApiSpecService, test golden Pest, fixture openapi.json, config openapi.php, CacheController, seeders). Convenzioni di workflow riusate dal repo di riferimento `C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\product_image_discovery_admin` (AGENTS.md, RULES.md, PROGRESS.md, LESSON.md, loop PR/Copilot).

**Decisioni utente:** dev locale con **MySQL via Herd** + **Redis via Herd** (test: SQLite `:memory:` + cache `array`); **UI in inglese**; stack starter kit `laravel/react-starter-kit` (Inertia 3 + React 19 + TS + Tailwind 4 + shadcn/ui), Pest, Pint, Larastan max, Vitest, Playwright.

**Ambiente verificato:** Herd PHP 8.5.7, Node 25.2.1, composer (Herd), gh CLI, copilot CLI — tutti disponibili.

---

## Deep Analysis — bug e problemi trovati negli skeleton

Da fixare come subtask espliciti (riferimenti a `OpenApiSpecService.php` skeleton):

| # | Problema | Dove | Fix pianificato |
|---|---|---|---|
| B1 | **Dipendenza nascosta da `Auth::`** dentro il service (`Auth::user()` in `filterForUser` riga 199, `Auth::id()` in `flushCache` riga 65). Viola DI, complica i test (i golden test dichiarano "nessun utente autenticato" ma il service chiama Auth) | Service | Refactor: `filterForUser(..., bool $isAdmin = false)` e `flushCache(bool $includeStale, ?int $actorId)`; decide il controller (T4.2) |
| B2 | **Cache poisoning**: qualunque JSON valido dell'upstream viene cachato 1h senza validare che sia una spec OpenAPI (righe 92-95) | Service | Validare shape minima (`openapi`+`info`+`paths`) prima del put; `InvalidOpenApiSpecException` + fallback stale (T4.3) |
| B3 | **SSRF**: nessuna validazione di `upstream_url` (schema/host) prima del fetch | Service | Whitelist `allowed_schemes`/`allowed_hosts` in config, check `parse_url` pre-fetch (T4.4) |
| B4 | **Pruning components incompleto**: perde `securitySchemes` (referenziati per nome via `security`, non via `$ref`), `$ref` a livello path-item, `webhooks`/`pathItems` OpenAPI 3.1 | Service | Seed della reachability esteso + fixture 3.1 dedicata (T4.5) |
| B5 | Ruolo `'admin'` hardcoded | Service/gate | `config('openapi.admin_role')` (T4.6) |
| B6 | Stale cache `Cache::forever` senza TTL (by design ma non documentato) | Service | Docblock + nota in RULES.md; `flushCache(true)` la rimuove (T4.6) |
| B7 | `injectServers` accetta qualunque array senza validare shape `{url, description?}` | Service | Validazione entry, skip+warning su entry malformate (T4.6) |
| B8 | **Gap di test**: nessun test su errori upstream (down/malformed), `prune_components=false`, grant vuoti, normalizzazione case endpoint key, cache-hit senza HTTP, securitySchemes | Test | Suite estesa (T4.7) |
| B9 | Fixture path hardcoded `tests/Fixtures/openapi.json` nel test ma file fornito in root della cartella piano | Test | Copia in `tests/Fixtures/` (T4.1) |

**Migliorie/feature mancanti incluse nel piano:** guard "ultimo admin" (no self-delete/demote), `Cache-Control: private, no-store` sul proxy (contenuto per-utente), rate limiting login, evento `Failed` in audit (la spec lo dà opzionale → lo includo), comando `auth-logs:prune`, validazione URL nei server playground, empty/loading/error states UI, dark/light mode.

**Fuori scope v1 (documentate in README come roadmap):** supporto multi-spec, invalidazione cache via webhook/ETag upstream, 2FA, export audit CSV.

---

## Workflow Contract (vale per OGNI macro task — verrà codificato in AGENTS.md)

- Branch `task/<nome>` da `main` per ogni macro task. Ogni subtask = branch figlio + **PR verso il macro branch**. Macro task concluso = PR macro branch → `main`.
- **Definition of Done di ogni subtask:**
  1. Obiettivo preciso + dettagli implementativi + guardrail: **Pest** (PHP), **Vitest** (JS), **Playwright** per ogni interazione UI/UX (skip se solo backend).
  2. Loop locale: tutti i test verdi + `pint --test` + Larastan max puliti → **review Copilot locale**: `copilot --autopilot --yolo -p "/review <diff branch vs origin/main>"` passando il diff completo del branch (se troppo grande → salvarlo su file temp e passare il file) → ripetere fino a **zero commenti**.
  3. Push → apertura PR (gh CLI) → **aggiungere Copilot come reviewer** (`gh pr edit <PR> --add-reviewer copilot`; in pratica fallisce su questo repo → usare il fallback GraphQL `requestReviewsByLogin` con `copilot-pull-request-reviewer[bot]`, vedi AGENTS.md) → verificare che la review sia partita.
  4. Attendere **CI tutta verde** + commenti Copilot → fixare test rotti e commenti → ri-push e nuova review in loop.
  5. Solo con tutto ok: **merge** e avanti col task successivo.
- `docs/PROGRESS.md` aggiornato ad ogni step (così una sessione interrotta riparte esattamente da dov'era); `docs/LESSON.md` aggiornato ad ogni scoperta/fix da Copilot. Entrambi passati nel contesto di ogni subagent e riletti a inizio sessione.
- Regole macchina: **sempre PHP/composer di Herd, mai XAMPP**; `.gitattributes` eol=lf.
- Questo piano viene copiato nel repo come `docs/PLAN.md` (richiesta utente "salva il piano in un file md").

---

## Macro Task 1 — `task/project-conventions` (PRIMO, prima di ogni riga di codice)

Obiettivo: codificare regole/skill/agents in formato Claude così che la procedura sopravviva alla fine sessione. Adattato dal repo di riferimento (Operating Rules, Branch & PR Loop, fallback GraphQL Copilot, regola Herd-non-XAMPP, template LESSON/PROGRESS).

- **1.1 AGENTS.md + CLAUDE.md** — AGENTS.md: Operating Rules (strict_types, early return, no array_merge in loop, Actions-oriented, FormRequest/Resource/Enum), Branch & PR Loop con comandi esatti, gate di test, comando copilot review locale + fallback GraphQL, regola Herd. CLAUDE.md: puntatore ad AGENTS.md + quick facts (stack, comandi test, percorsi chiave). DoD: file committati e mergiati (solo docs, niente test).
- **1.2 docs/RULES.md + docs/PROGRESS.md + docs/LESSON.md + docs/PLAN.md** — RULES.md: standard §14 spec + template DoD + regola "Playwright obbligatorio per UI". PROGRESS.md: tabella macro task pre-popolata da questo piano. LESSON.md: template datato. PLAN.md: questo piano.
- **1.3 Skill di resume `.claude/skills/scalar-openapi-doc-plan/SKILL.md`** — frontmatter con description tipo "Continue or resume the scalar-openapi-doc implementation… enforce branch/PR/Copilot/test rules"; Start Here → AGENTS.md, RULES.md, PROGRESS.md, LESSON.md. Più `.claude/settings.json` con allowlist comandi progetto.

Dipendenze: nessuna. No Playwright (solo docs).

## Macro Task 2 — `task/bootstrap` (scaffold + tooling qualità + CI)

Obiettivo: app Laravel 13 + react-starter-kit funzionante su Herd, con Pint/Larastan/Pest/Vitest/Playwright e CI verde.

- **2.1 Starter kit in repo non-vuoto** — `laravel new` rifiuta dir non vuote: scaffold in dir temp sibling (`laravel new` o `composer create-project laravel/react-starter-kit`), copia nel repo preservando `.git` e LICENSE (README starter accantonato, riscritto in T9). `.gitattributes` eol=lf. Verificare wiring auth del kit (Fortify atteso in L13) e annotare in LESSON.md. DoD: `php artisan about` ok su Herd, `npm run build` ok, login page renderizza, test Pest del kit verdi. `.env` dev: MySQL Herd (`scalar_openapi_doc` db) + `CACHE_STORE=redis` (Redis Herd).
- **2.2 Tooling qualità** — larastan (level max) + `phpstan.neon.dist`; `pint.json`; `phpunit.xml`: sqlite `:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`. Vitest (jsdom, `pool` Windows-safe) + test componente sample. Playwright config con `webServer: php artisan serve` + `.env.testing.e2e` (sqlite file, seed dedicato). DoD: 5 comandi verdi in locale (pint, larastan, pest, vitest+build, playwright hello-world).
- **2.3 CI `.github/workflows/ci.yml`** — ubuntu-latest, `shivammathur/setup-php` PHP 8.5 (fallback 8.4 documentato, Laravel 13 richiede `^8.3`). Test su sqlite+array cache (niente servizi MySQL/Redis in CI: il codice è driver-agnostic via facades). Job paralleli: pint --test, larastan, pest, tsc+vitest+vite build, playwright (artifacts trace on failure). DoD: CI verde sulla PR macro.

Dipendenze: T1. No Playwright reale (stub e2e ok).

## Macro Task 3 — `task/rbac-data-model` (DB, ruoli, modelli, seeder) — solo backend

- **3.1 spatie/laravel-permission + ruoli** — install, `HasRoles` su User, `config/openapi.php` con chiave `admin_role` (fix B5), integrazione `RoleSeeder`/`AdminUserSeeder` (env `ADMIN_EMAIL`/`ADMIN_PASSWORD`)/`DatabaseSeeder` dagli skeleton. Guardrail Pest: seeder crea ruoli+admin; middleware `role:admin` registrato e 403 per user semplice.
- **3.2 Migrazioni custom + modelli + enum** — `user_allowed_tags` (UNIQUE user_id+tag, CASCADE), `user_allowed_endpoints` (UNIQUE user_id+method+path, CASCADE), `scalar_servers`, `auth_logs` (user_id nullable SET NULL, email snapshot, event, ip 45, ua 512, solo created_at, indici). Enum `AuthEvent`/`HttpVerb`. Modelli + relazioni + casts + `$fillable`. Guardrail Pest: vincoli unique, cascade, SET NULL, niente updated_at su auth_logs.
- **3.3 `ReplaceUserAccessAction`** — transazione: delete righe utente → bulk insert chunked (ADR-07, no array_merge in loop). Guardrail Pest: replace corretto, array vuoti svuotano, rollback atomico su failure.

Dipendenze: T2. Parallelo possibile con T4.

## Macro Task 4 — `task/openapi-service` (core + golden test + hardening) — solo backend

Cuore di sicurezza: slice piccole, una review per fix.

- **4.1 Integrazione baseline** — copia skeleton: `app/Services/OpenApiSpecService.php`, `config/openapi.php`, test golden in `tests/Feature/`, fixture in `tests/Fixtures/openapi.json` (fix B9). DoD: 9 golden test verdi as-is, Larastan max pulito.
- **4.2 Rimozione dipendenza Auth (B1)** — `filterForUser(array $spec, Collection $tags, Collection $endpoints, bool $isAdmin = false)`; `flushCache(bool $includeStale = false, ?int $actorId = null)`. Guardrail: test parametrizzati admin-bypass (`isAdmin=true + admin_sees_all=true` → spec integrale; `admin_sees_all=false` → filtra comunque).
- **4.3 Validazione risposta upstream (B2)** — shape minima prima del cache put; `InvalidOpenApiSpecException`; fallback stale. Guardrail Pest con `Http::fake`: HTML/`{"foo":1}`/body vuoto → niente in cache, stale servita se esiste, throw se no.
- **4.4 Guardia SSRF (B3)** — config `openapi.allowed_schemes` (default `['https']`) + `openapi.allowed_hosts` (env, csv); check pre-fetch. Guardrail: `file://`, IP metadata `169.254.169.254`, host fuori whitelist → rifiutati, `Http::assertNothingSent()`.
- **4.5 Pruning completo (B4)** — seed reachability con: securitySchemes per nome da `security` root+operation; `$ref` a livello path-item; `webhooks`/`components.pathItems` 3.1. Fixture `openapi31.json` aggiuntiva. Guardrail golden: scheme usato preservato, inutilizzato potato, schema referenziato da webhook sopravvive.
- **4.6 Hardening restante (B5, B6, B7)** — `admin_role` configurabile nei punti di check ruolo; docblock semantica stale; `injectServers` valida `{url: valid URL, description?: string}` con skip+warning. Guardrail: test injectServers malformati; `flushCache(true)` rimuove stale.
- **4.7 Copertura mancante (B8)** — upstream down→stale; down+no stale→throw; `prune_components=false`; grant vuoti; normalizzazione `get`→`GET` nelle chiavi endpoint (canonical `UPPER(method) path` normalizzata nel service); cache-hit→`Http::assertNothingSent`.

Dipendenze: T2 (T3 non necessario: dopo 4.2 il service è user-agnostic).

## Macro Task 5 — `task/scalar-proxy` (Scalar + proxy + flush cache + dashboard)

- **5.1 scalar/laravel + auth wrapping** *(subtask a rischio)* — install, publish config; `'url' => '/api-docs/openapi.json'`, tema purple/modern. Verificare se la rotta del package accetta middleware via config; se no: disabilitare rotta package e registrare `GET /scalar` proprio in gruppo `web,auth` renderizzando la Blade pubblicata (`scalar-views`). Gate `viewScalar` (hasAnyRole con nomi ruolo configurabili). Guardrail Pest: guest→redirect login; senza ruolo→403; user/admin→200.
- **5.2 Proxy spec + meta endpoints** — `ApiDocsController`: `spec()` = fetchRaw → filterForUser (grant da DB, isAdmin dal ruolo config) → injectServers (server attivi per sort_order) → JSON con `Cache-Control: private, no-store`. `metaTags()`/`metaEndpoints()` sotto `auth, role:admin`. Rotte §9. Guardrail Pest: due utenti con grant diversi ricevono spec diverse; operazione non concessa assente; admin spec integrale; guest 401; meta 403 per user; servers iniettati solo se attivi.
- **5.3 Flush cache: action + command** — `CacheController` adattato (actorId esplicito) → `DELETE /admin/openapi-cache`; comando `openapi:refresh --include-stale`. Guardrail Pest: 403 per user, flush+flash per admin; comando svuota chiave.
- **5.4 Dashboard + nav (prima UI reale)** — dashboard/sidebar starter kit adattate: link "API Documentation" (pagina intera → `/scalar`), voci admin-only (Users, Servers, Auth Logs, Flush Cache con ConfirmDialog+toast). Vitest: rendering condizionale nav per ruolo. **Playwright:** login user → sidebar solo docs, `/scalar` carica e Scalar renderizza; login admin → voci admin visibili; Flush Cache → dialog → toast successo; nav diretta `/admin/users` da user → 403.

Dipendenze: T3+T4.

## Macro Task 6 — `task/admin-users` (CRUD utenti + grant anti-tampering)

- **6.1 FormRequest + controller (backend)** — `StoreUserRequest`/`UpdateUserRequest` (§6.5): name/email/password (confirmed su create, nullable su update), `roles` in nomi configurati, `allowed_tags` `Rule::in(extractTags(fetchRaw()))`, `allowed_endpoints` `{method,path}` validati vs `extractEndpoints()` (ADR-05). `Admin\UserController` Inertia CRUD; store/update: `syncRoles` + `ReplaceUserAccessAction` in transazione. Guard "ultimo admin": vietato eliminare se stessi / rimuovere l'ultimo admin. Guardrail Pest: tag/endpoint manomessi → 422; replace corretto; password opzionale in update; guard ultimo admin; index con paginazione+search.
- **6.2 Componenti UI condivisi** — `data-table.tsx` (TanStack+shadcn, sort, paginazione server-side), `multi-select.tsx` (combobox multi con ricerca), `confirm-dialog.tsx`, `role-badge.tsx`. **Vitest:** MultiSelect select/deselect/search/preselezione; DataTable righe+callback paginazione.
- **6.3 Pagine Users (Inertia)** — `admin/users/index.tsx` (griglia email/nome/ruoli, search testuale, filtro ruolo, paginazione server-side, azioni riga) e `form.tsx` create/edit con MultiSelect alimentate da `/api-docs/meta/*`, **preselezione in edit**. **Playwright:** create user (form, ruolo, 2 tag + 1 endpoint via search multiselect, submit, toast, appare in griglia); edit (preselezioni visibili, rimuovi una, salva, riapri → persistito); delete con confirm; search filtra; filtro ruolo; paginazione.

Dipendenze: T5.

## Macro Task 7 — `task/admin-servers-audit` (server playground + audit)

- **7.1 Servers CRUD** — `ServerRequest` (url valid URL required, description nullable, sort_order int, is_active bool), `Admin\ServerController`, pagina `admin/servers/index.tsx`. Guardrail Pest: CRUD + integrazione "solo attivi iniettati dal proxy". **Playwright:** aggiungi server → appare; toggle inactive → sparisce dalla spec del proxy; edit/delete con confirm.
- **7.2 Listener audit** *(backend-only)* — `LogAuthEventAction` + listener `Illuminate\Auth\Events\{Login,Logout,Failed}` (resilienti al wiring Fortify); comando `auth-logs:prune --days=N` + scheduler. Guardrail Pest: login ok → riga `login` (user_id, email, ip, UA); password errata → riga `failed` (email snapshot, user_id null); logout → riga `logout`; righe sopravvivono alla cancellazione utente. Audit immutabile (nessuna rotta update/delete).
- **7.3 Pagina Auth Logs** — `Admin\AuthLogController@index` (filtri utente/email, evento, intervallo date; paginazione server-side, più recenti prima) + `admin/auth-logs/index.tsx` read-only con badge evento. **Playwright:** login/logout reali poi da admin verifica righe; filtro event=failed dopo tentativo errato; empty-state su filtro date.

Dipendenze: T6 (componenti condivisi). 7.1 ∥ 7.2.

## Macro Task 8 — `task/hardening-polish` (security pass + E2E completo + UX)

- **8.1 Security pass** — rate limiting login (429 dopo N tentativi), audit CSRF su tutte le mutazioni, audit mass-assignment, header `Cache-Control: private, no-store` verificato sul proxy, Larastan max su tutta l'app, `composer audit` + `npm audit`. Guardrail Pest: 429 su brute force; header proxy.
- **8.2 Matrice autorizzazioni E2E** — Playwright spec della matrice §4.2 (user vs admin su tutte le rotte) + scenario chiave: **due utenti seedati con grant disgiunti → la sidebar Scalar mostra a ciascuno solo i propri tag; admin vede tutto**.
- **8.3 Polish UX/a11y** — dark/light, loading/empty/error states ovunque, focus management nei dialog, responsive. Playwright smoke keyboard-nav (tab nel form utente, Esc chiude dialog).

Dipendenze: T5–T7.

## Macro Task 9 — `task/release` (README WOW + consolidamento knowledge + tag)

- **9.1 README WOW** (modello lopadova/AskMyDocs) — badge (CI, license, PHP/Laravel), TOC, "cosa ha di innovativo" (filtraggio per-utente server-side, grant UNION, pruning transitivo components, stale-on-error, anti-tampering), Quick Start a prova di junior (Herd, tabella `.env` §18.1, seed admin, comandi step-by-step verificati su clone pulito), matrice testing, note sicurezza. **Niente banner/screenshot:** `resources/banner.png` non fornito (verificare di nuovo a fine lavori; se comparso, includerlo).
- **9.2 Consolidamento LESSON.md → rules/skills/AGENTS.md** *(task finale richiesto)* — rileggere LESSON.md e tutto l'appreso; promuovere ogni lezione ricorrente in regola/skill/aggiornamento AGENTS.md e CLAUDE.md; archiviare le lezioni promosse.
- **9.3 Tag + release GitHub** — `git tag v1.0.0` + `gh release create v1.0.0` con changelog dalle PR macro mergiate.

Dipendenze: tutte le precedenti.

---

## Ordine ed eventuale parallelismo

`1 → 2 → {3 ∥ 4} → 5 → 6 → 7 → 8 → 9`. Dentro T7: 7.1 ∥ 7.2. Subagent paralleli ricevono sempre nel prompt il contenuto di `docs/LESSON.md` + regole di AGENTS.md.

## CI (riepilogo)

`ci.yml` unico: ubuntu-latest, setup-php 8.5 (fallback 8.4), job paralleli pint/larastan/pest(sqlite+array)/tsc+vitest+build/playwright(sqlite file, seed, `php artisan serve` come webServer, trace artifacts). Cache composer+npm. Required checks su `main` e sui branch macro.

## Rischi

| Rischio | Mitigazione |
|---|---|
| Starter kit rifiuta dir non vuota | Scaffold in temp sibling, copia preservando `.git`/LICENSE (T2.1) |
| Rotta Scalar non wrappabile con middleware via config | Verifica vendor; fallback: rotta propria `/scalar` con Blade pubblicata (T5.1) |
| Wiring Fortify diverso nel kit L13 | Verifica in T2.1; listener su `Illuminate\Auth\Events\*` (resilienti) (T7.2) |
| Quirk Windows (Vitest pool, Playwright+Herd, CRLF) | pool Windows-safe, webServer `php artisan serve` Herd, `.gitattributes` eol=lf, regola mai-XAMPP in AGENTS.md |
| PHP 8.5 instabile su CI | Pin setup-php, fallback 8.4 documentato (Laravel 13 = `^8.3`) |
| Copilot CLI/review non disponibile temporaneamente | Registrare blocker in PROGRESS.md, non saltare il gate silenziosamente |

## Verification (per l'intero progetto)

- Ogni subtask: `vendor/bin/pint --test` + `vendor/bin/phpstan analyse` + `php artisan test` (Pest) + `npm run test` (Vitest) + `npm run build` + `npx playwright test` (se UI) tutti verdi in locale (Herd PHP 8.5) e su CI.
- E2E finale: matrice autorizzazioni completa + scenario "due utenti, grant disgiunti, sidebar Scalar diverse" (T8.2).
- Quick Start del README verificato su clone pulito prima del tag (T9.1).
