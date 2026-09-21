# Riset API: WordPress Abilities API + MCP Adapter

> Target implementasi: plugin **Bulk Catalog Studio** (WooCommerce Store Kit) untuk situs
> https://cf.djavaweb.com — WordPress 7.1.1 + WooCommerce + Elementor, HTTPS, di belakang
> Cloudflare. Situs sudah punya MCP Adapter aktif (`/wp-json/mcp/mcp-adapter-default-server`
> → HTTP 401 saat belum terautentikasi) dan REST API Abilities (`wp-abilities/v1`) hidup.
>
> Semua cuplikan kode di bawah disalin PERSIS dari sumber resmi yang berhasil di-fetch
> (URL dicantumkan di bawah tiap cuplikan). Tanggal riset: 21 September 2026.

---

## Ringkasan arsitektur

1. **Abilities API** (core WordPress sejak 6.9): registry terpusat tempat plugin/theme/core
   mendaftarkan "ability" bernama (`namespace/ability-name`) dengan input/output JSON Schema,
   `permission_callback`, dan `execute_callback`.
2. **MCP Adapter** (`WordPress/mcp-adapter`): paket resmi inisiatif *AI Building Blocks for
   WordPress* yang menjembatani Abilities API ke Model Context Protocol — ability menjadi
   MCP **tools**, **resources**, dan **prompts**.
3. Adapter menyediakan **default server** `mcp-adapter-default-server` di
   `/wp-json/mcp/mcp-adapter-default-server` (HTTP) dan via WP-CLI STDIO
   (`wp mcp-adapter serve`).
4. Ability **tidak otomatis** tampil di MCP: default server hanya mengekspos ability dengan
   flag `meta.mcp.public = true` (atau `meta.public = true`), dan aksesnya lewat tiga
   meta-tools bawaan (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`,
   `mcp-adapter-execute-ability`), bukan sebagai tool terpisah di `tools/list`.
5. **MCP client** (Claude Desktop/Code, Cursor, VS Code) terhubung lokal via STDIO (WP-CLI)
   atau remote via proxy Node.js `@automattic/mcp-wordpress-remote` yang
   menerjemahkan STDIO → HTTP REST dengan Application Password.
6. **WooCommerce** memakai arsitektur yang sama: ability-ability WooCommerce tampil lewat
   MCP Adapter default server; endpoint lama `/wp-json/woocommerce/mcp` **deprecated**.
7. Alur eksekusi satu arah: *register ability → adapter expose → agent call*, dan
   `permission_callback` ability **selalu** dijalankan pada setiap invokasi, apapun
   transportnya. MCP discovery **tidak** melewati permission WordPress.
8. Untuk plugin kami: cukup register ability + pastikan MCP Adapter aktif — tidak perlu
   server kustom, tidak perlu flag `mcp_integration` WooCommerce.

---

## 1. `wp_register_ability()` dan `wp_register_ability_category()`

### 1.1 Signature `wp_register_ability()`

```
wp_register_ability( string $name,  array $args ): WP\Ability|null
```

Sumber (referensi fungsi resmi core, termasuk source code-nya):
https://developer.wordpress.org/reference/functions/wp_register_ability/

Contoh resmi (disalin persis):

```php
function my_plugin_register_abilities(): void {
    wp_register_ability(
        'my-plugin/analyze-text',
        array(
            'label'               => __( 'Analyze Text', 'my-plugin' ),
            'description'         => __( 'Performs sentiment analysis on provided text.', 'my-plugin' ),
            'category'            => 'text-processing',
            'input_schema'        => array(
                'type'        => 'string',
                'description' => __( 'The text to be analyzed.', 'my-plugin' ),
                'minLength'   => 10,
                'required'    => true,
            ),
            'output_schema'       => array(
                'type'        => 'string',
                'enum'        => array( 'positive', 'negative', 'neutral' ),
                'description' => __( 'The sentiment result: positive, negative, or neutral.', 'my-plugin' ),
                'required'    => true,
            ),
            'execute_callback'    => 'my_plugin_analyze_text',
            'permission_callback' => 'my_plugin_can_analyze_text',
            'meta'                => array(
                'annotations' => array(
                    'readonly' => true,
                ),
                'public'      => true,
            ),
        )
    );
}
add_action( 'wp_abilities_api_init', 'my_plugin_register_abilities' );
```
Sumber: https://developer.wordpress.org/reference/functions/wp_register_ability/

**Detail parameter `$args`** (dokumentasi resmi, disalin dari halaman yang sama):

- `$name` (`string`, required): nama ability, **harus** namespaced (`my-plugin/my-ability`),
  hanya boleh berisi karakter alfanumerik **lowercase**, dash, dan garis miring.
- `label` (`string`, **Required**): label human-readable, sebaiknya translatable.
- `description` (`string`, **Required**): penjelasan detail apa yang dilakukan ability,
  kapan dipakai — penting agar AI agent paham.
- `category` (`string`, **Required**): slug kategori; kategori **harus sudah
  diregistrasi** lewat `wp_register_ability_category()` sebelum ability didaftarkan.
- `execute_callback` (`callable`, **Required**): callback eksekusi; menerima input opsional,
  mengembalikan hasil (tipe apa pun) atau `WP_Error` saat gagal.
- `permission_callback` (`callable`, **Required**): cek permission sebelum eksekusi;
  menerima input yang sama dengan execute callback; mengembalikan `bool` atau `WP_Error`.
  Jika `input_schema` diset dan input tidak valid, permission callback **tidak dipanggil**.
- `input_schema` (`array`, Optional): JSON Schema validasi input. WordPress memakai validator
  berbasis subset **JSON Schema Draft-4** (sama dengan REST API schema).
- `output_schema` (`array`, Optional): JSON Schema validasi output.
- `meta` (`array`, Optional), berisi:
  - `annotations` (`array`): hints perilaku — `instructions` (string), `readonly` (bool),
    `destructive` (bool), `idempotent` (bool).
  - `public` (`bool`, default `false`): flag high-level "ability tersedia untuk client
    (REST API, MCP, AI agents)"; menjadi default untuk flag per-channel.
  - `show_in_rest` (`bool`): expose ability di REST API; default = nilai `public`.
  - `mcp` (`array`): metadata khusus MCP — `mcp.public` (bool) dan `mcp.type`
    (lihat bagian 2).
- `ability_class` (`string`, Optional): FQCN kustom yang **harus** extend `WP_Ability`.

**Return:** `WP_Ability|null` — instance ability jika sukses, `null` jika gagal.

**Versi minimum:** `6.9.0` (changelog resmi). Source code core juga memvalidasi hook:

```php
function wp_register_ability( string $name, array $args ): ?WP_Ability {
	if ( ! doing_action( 'wp_abilities_api_init' ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: 1: wp_abilities_api_init, 2: string value of the ability name. */
				__( 'Abilities must be registered on the %1$s action. The ability %2$s was not registered.' ),
				'<code>wp_abilities_api_init</code>',
				'<code>' . esc_html( $name ) . '</code>'
			),
			'6.9.0'
		);
		return null;
	}

	$registry = WP_Abilities_Registry::get_instance();
	if ( null === $registry ) {
		return null;
	}

	return $registry->register( $name, $args );
}
```
Sumber: https://developer.wordpress.org/reference/functions/wp_register_ability/

> **Hook yang benar: `wp_abilities_api_init`** (bukan `abilities_api_init`).
> Source code core di atas memverifikasi `doing_action( 'wp_abilities_api_init' )` —
> registrasi di luar hook itu menghasilkan `null` + `_doing_it_wrong()`. Beberapa contoh
> lama (mis. README `wc-mcp-ability`) masih memakai nama hook lama `abilities_api_init`;
> untuk WP 7.x gunakan `wp_abilities_api_init`.

**Hook kategori: `wp_abilities_api_categories_init`** (dieksekusi **sebelum**
`wp_abilities_api_init`). Sumber hook & pola: 
https://developer.woocommerce.com/docs/features/mcp/ dan
https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md

### 1.2 Signature `wp_register_ability_category()`

```
wp_register_ability_category( string $slug, array $args ): ?\WP_Ability_Category
```

Sumber: https://github.com/WordPress/abilities-api/blob/trunk/docs/php-api.md

- `$slug` (`string`): identifier unik kategori. **Hanya** huruf kecil `a-z`, angka `0-9`,
  dan hyphen. Invalid: `Data-Retrieval` (uppercase), `data_retrieval` (underscore),
  `data.retrieval`, `data/retrieval`, `-data`, `data-`, `data--retrieval`.
- `$args`:
  - `label` (`string`, **Required**)
  - `description` (`string`, **Required**)
  - `meta` (`array`, Optional)
- **Return:** `?\WP_Ability_Category` — `null` jika gagal (argumen invalid, slug duplikat).
- **Wajib** didaftarkan pada action `wp_abilities_api_categories_init`.

Contoh resmi:

```php
add_action( 'wp_abilities_api_categories_init', 'my_plugin_register_categories' );
function my_plugin_register_categories() {
    wp_register_ability_category( 'data-retrieval', array(
        'label' => __( 'Data Retrieval', 'my-plugin' ),
        'description' => __( 'Abilities that retrieve and return data from the WordPress site.', 'my-plugin' ),
    ));
}
```
Sumber: https://github.com/WordPress/abilities-api/blob/trunk/docs/php-api.md

Core sudah menyediakan dua kategori siap pakai: **`site`** dan **`user`**.
Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md

### 1.3 Fungsi helper terkait (semua terverifikasi di doc PHP API)

- `wp_unregister_ability_category( string $slug ): ?\WP_Ability_Category`
- `wp_get_ability_category( string $slug ): ?\WP_Ability_Category`
- `wp_get_ability_categories(): array`
- `wp_has_ability( string $name ): bool`
- `wp_get_ability( string $name ): ?WP_Ability`
- `wp_get_abilities(): WP_Ability[]`
- `$ability->execute( $input = null )` — validasi input + permission check lalu eksekusi
- `$ability->check_permissions( $input = null )` — cek permission tanpa eksekusi

Sumber: https://github.com/WordPress/abilities-api/blob/trunk/docs/php-api.md

### 1.4 Ability bawaan core (untuk testing adapter)

WordPress 6.9 menyertakan 3 ability default: `core/get-site-info`,
`core/get-user-info`, `core/get-environment-info`.
Sumber: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/

---

## 2. Metadata `meta.mcp.public` dan `meta.mcp.type`

Sumber utama (doc resmi adapter, trunk):
https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md

### 2.1 Perilaku presisi flag

> "WordPress abilities are NOT accessible via the default MCP server by default. Set the
> high-level `meta.public` flag to `true` to make an ability available to clients,
> including MCP. An explicit `meta.mcp.public` value overrides that default for MCP only."

Aturan lengkap (disalin dari doc yang sama):

```php
'meta' => [
    'public' => true, // Expose to clients, including MCP
    'mcp' => [
        'type'   => 'tool' // Optional: 'tool' (default), 'resource', or 'prompt'
    ],
    'annotations' => [...] // Optional MCP annotations
]
```

- **Catatan versi (penting untuk WP 7.1.1):** "How far `meta.public` reaches depends on the
  WordPress version. WordPress core starts applying `meta.public` to the REST API
  (`meta.show_in_rest`) in version 7.1. On WordPress 6.9 and 7.0, the MCP Adapter honors
  `meta.public` for MCP exposure, but REST API access still requires setting
  `meta.show_in_rest` to `true`."
- **Opt-out dari MCP** (public tapi tidak di MCP):

```php
'meta' => [
    'public' => true,
    'mcp' => [
        'public' => false,
    ],
]
```

- **MCP saja** (tidak ikut channel lain):

```php
'meta' => [
    'mcp' => [
        'public' => true,
    ],
]
```

### 2.2 `meta.mcp.type`

> "The `type` parameter specifies how the ability should be exposed in the MCP server:
> - **`tool`** (default): Exposed as a callable tool via the default server's discovery
> - **`resource`**: Exposed as a resource (requires `uri` in meta)
> - **`prompt`**: Exposed as a prompt (requires `arguments` in meta)
> If not specified, abilities default to `type: 'tool'`."

- Untuk `resource`: set `'uri' => 'wordpress://...'` di `meta` dan
  `'mcp' => ['type' => 'resource']`.
- Untuk `prompt`: `input_schema` otomatis dikonversi ke `arguments` MCP; `execute_callback`
  harus mengembalikan struktur `{ messages: [...] }`.

### 2.3 Perilaku di default server (sangat penting)

Dari README adapter:

> "On the default server, public abilities are accessed through `mcp-adapter/discover-abilities`,
> `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` rather than being
> auto-registered individually in `tools/list`."

Artinya: ability `mcp.public=true` **tidak muncul satu-per-satu** di `tools/list` default
server; agent menemukannya lewat tool `mcp-adapter-discover-abilities` lalu mengeksekusinya
lewat `mcp-adapter-execute-ability` dengan parameter nama ability. Nama ability lolos
**apa adanya** (tidak disanitasi) pada konteks ini.

Tanpa `meta.mcp.public` (atau `meta.public`), ability hanya bisa diakses lewat
**custom MCP server** yang mendaftarkannya secara eksplisit.

### 2.4 Nama tool MCP (sanitasi)

- Spec MCP (2025-11-25) membatasi nama tool ke `A-Za-z0-9_.-`, maksimal 128 karakter.
- Adapter otomatis mensanitasi: `my-plugin/my-tool` → `my-plugin-my-tool`
  (garis miring → dash, transliterasi aksen, dst.).
- Override dengan filter `mcp_adapter_tool_name` (menerima `$name` dan `WP_Ability`).
- Sanitasi hanya berlaku untuk tool di **custom server**; di default server nama ability
  diteruskan apa adanya via meta-tools.

Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md

---

## 3. Custom MCP server via `mcp_adapter_init`

### 3.1 Kapan perlu?

Dari post resmi developer.wordpress.org:

> "While the MCP Adapter default server should cover most requirements, you may want to
> create a custom MCP server for your plugin. This allows you to have more control over
> how your abilities are exposed as MCP tools."

Kelebihan custom server:
- Ability muncul **langsung sebagai tool individu** di `tools/list` (bukan lewat meta-tools),
  tanpa perlu flag `meta.mcp.public`.
- Kontrol penuh: transport kustom, permission callback transport (auth kustom, mis. API key),
  error handler dan observability handler kustom (integrasi logging/monitoring).
- Pembagian logis: satu server per domain plugin (mis. hanya ability Bulk Catalog Studio).

**Rekomendasi untuk Bulk Catalog Studio:** default server **cukup** untuk kasus standar
(register ability + `meta.mcp.public`). Buat custom server hanya jika ingin tools tampil
individual di `tools/list` atau butuh auth/observability khusus.

### 3.2 Inisialisasi adapter dari plugin (Composer)

```php
if ( ! class_exists( WP\MCP\Core\McpAdapter::class ) ) {
    // check if the MCP Adapter class is available, if not show some sort of error or admin notice
    return;
}

// Initialize MCP Adapter and its default server.
WP\MCP\Core\McpAdapter::instance();
```
Sumber: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/

```php
use WP\MCP\Core\McpAdapter;

// 1. Check if MCP Adapter is available
if ( ! class_exists( McpAdapter::class ) ) {
    // Handle missing dependency (show admin notice, etc.)
    return;
}

// 2. Initialize the adapter
McpAdapter::instance();
// That's it!
```
Sumber: https://github.com/WordPress/mcp-adapter

### 3.3 `create_server()` — parameter lengkap

```php
add_action( 'mcp_adapter_init', function($adapter) {
    $adapter->create_server(
        'my-server-id',                    // Unique server identifier
        'my-namespace',                    // REST API namespace
        'mcp',                            // REST API route
        'My MCP Server',                  // Server name
        'Description of my server',       // Server description
        'v1.0.0',                        // Server version
        [                                 // Transport methods
            \WP\MCP\Transport\HttpTransport::class,  // Recommended: MCP 2025-06-18 compliant
        ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, // Error handler
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class, // Observability handler
        ['my-plugin/my-ability'],         // Abilities to expose as tools
        [],                              // Resources (optional)
        [],                              // Prompts (optional)
    );
});
```
Sumber: https://github.com/WordPress/mcp-adapter

Parameter ke-13 (opsional): **transport permission callback** — tanpa ini default-nya
`is_user_logged_in()`:

```php
    [], // prompts
    function(): bool {  // Permission callback
        return current_user_can('manage_options');
    }
```
Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/transport-permissions.md

Contoh end-to-end dari post resmi (plugin List All URLs):

```php
add_action( 'mcp_adapter_init', 'list_all_urls_create_custom_mcp_server' );
/**
 * Create a custom MCP server for the List All URLs plugin.
 *
 * @param object $adapter WP\MCP\Core\McpAdapter The MCP Adapter instance.
 * @return void
 */
function list_all_urls_create_custom_mcp_server( $adapter ) {
    $adapter = WP\MCP\Core\McpAdapter::instance();
    $adapter->create_server(
        'list-all-urls-mcp-server',
        'list-all-urls-mcp-server',
        'mcp',
        'List All URLS MCP Server',
        'Custom MCP Server for the List All URLs plugin. Currently exposes only the list-all-urls/urls ability as an MCP Tool.',
        'v1.0.0',
        array(
            \WP\MCP\Transport\HttpTransport::class,
        ),
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        array( 'list-all-urls/urls' ),
    );
}
```
Sumber: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/

> "Notice that you don't need to enable the `meta.mcp.public` flag for the
> `list-all-urls/urls` ability, because you're explicitly exposing it via the custom
> MCP server."

### 3.4 Handler bawaan yang tersedia

Dari struktur repo adapter (README):
- Error handler: `ErrorLogMcpErrorHandler` (default), `NullMcpErrorHandler`;
  interface `McpErrorHandlerInterface` untuk handler kustom.
- Observability: `ErrorLogMcpObservabilityHandler`, `NullMcpObservabilityHandler`,
  `ConsoleObservabilityHandler`; interface `McpObservabilityHandlerInterface`.
- Transport: `HttpTransport` (MCP 2025-06-18, unified), STDIO (via WP-CLI);
  interface `McpTransportInterface` / `McpRestTransportInterface` untuk transport kustom.
- Kelas utama: `WP\MCP\Core\McpAdapter` (singleton, registry server),
  `WP\MCP\Core\McpServer` (konfigurasi per server).

Sumber: https://github.com/WordPress/mcp-adapter

---

## 4. Instalasi MCP Adapter

### 4.1 Dua jalur resmi

**Composer (metode utama / direkomendasikan)** — dari README adapter:

> "The MCP Adapter is designed to be installed as a Composer package. This is the primary
> and recommended installation method: `composer require wordpress/mcp-adapter`"

**Plugin (alternatif)** — unduh release zip dari GitHub Releases, atau:

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate --force
```
Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md

**Untuk situs produksi (kasus cf.djavaweb.com):** plugin dari GitHub Releases adalah
jalur paling aman untuk *situs* (zip release sudah menyertakan `vendor/`, tidak perlu
composer di server). Jalur Composer dipakai bila plugin kami **membundel** adapter sebagai
dependency-nya sendiri. Situs target sudah punya adapter aktif (endpoint merespons 401),
jadi plugin kami cukup bergantung padanya.

### 4.2 Jetpack Autoloader (konflik versi)

Dari README adapter:

> "When multiple plugins use the MCP Adapter, it's highly recommended to use the Jetpack
> Autoloader to prevent version conflicts. The Jetpack Autoloader ensures that only the
> latest version of shared packages is loaded, eliminating conflicts when different plugins
> use different versions of the same dependency."

```bash
composer require automattic/jetpack-autoloader
```

```php
<?php
// Load the Jetpack autoloader instead of vendor/autoload.php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';
```
Sumber: https://github.com/WordPress/mcp-adapter

Catatan: pakai `vendor/autoload_packages.php` (Jetpack Autoloader), **bukan**
`vendor/autoload.php`. Error *"The Composer autoloader was not found"* muncul bila plugin
dipasang dari source checkout tanpa `composer install` — zip release sudah termasuk `vendor/`.
Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md

### 4.3 Requirement versi

Dari README adapter: **PHP >= 7.4**, **WordPress >= 6.9**, dependency `php-mcp-schema`
(^0.1.0, tipe protokol MCP 2025-11-25).
Sumber: https://github.com/WordPress/mcp-adapter

Situs target (WP 7.1.1) memenuhi syarat. Contoh plugin demo WooCommerce mensyaratkan
PHP 8.0+; toolkit resmi Automattic memakai header `Requires PHP: 8.1` — praktis gunakan
PHP 8.x untuk plugin baru.

---

## 5. Autentikasi remote: `@automattic/mcp-wordpress-remote`

### 5.1 Konfigurasi minimal (Application Password)

```json
{
  "mcpServers": {
    "wordpress-mcp-server": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://yoursite.example/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "{admin_user}",
        "WP_API_PASSWORD": "{application-password}"
      }
    }
  }
}
```
Sumber: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/

### 5.2 Env vars penting (dari README paket)

| Variable | Description | Default |
| --- | --- | --- |
| `WP_API_URL` | URL lengkap endpoint MCP (mis. `…/wp-json/mcp/mcp-adapter-default-server`). **Domain telanjang** akan resolve ke endpoint legacy `wordpress-mcp` yang deprecated. | - (required) |
| `OAUTH_ENABLED` | Aktifkan OAuth | `true` |
| `WP_API_USERNAME` | WordPress username (legacy) | - |
| `WP_API_PASSWORD` | Application Password (legacy) | - |
| `JWT_TOKEN` | JWT token (alternatif) | - |
| `WOO_CUSTOMER_KEY` / `WOO_CUSTOMER_SECRET` | WooCommerce consumer key/secret | - |
| `CUSTOM_HEADERS` | Header kustom (JSON atau `Key:Value,Key:Value`) | - |
| `LOG_FILE` | Path file log | - |
| `LOG_LEVEL` | 0–3 | `2` |
| `WP_API_TIMEOUT_MS` | Timeout tool call (ms) | `120000` |
| `WP_API_INIT_TIMEOUT_MS` | Timeout handshake initialize (ms) | `25000` |
| `NODE_EXTRA_CA_CERTS` | CA ekstra (mkcert/corporate CA) | - |

Sumber: https://github.com/Automattic/mcp-wordpress-remote

> Penting: untuk memakai Application Password, nonaktifkan OAuth:
```json
{
  "env": {
    "OAUTH_ENABLED": "false",
    "WP_API_USERNAME": "your-username",
    "WP_API_PASSWORD": "your-application-password"
  }
}
```
Sumber: https://github.com/Automattic/mcp-wordpress-remote

### 5.3 HTTPS & Application Password

Dari doc WooCommerce:

> "Remote HTTP connections authenticate using a WordPress username and an Application
> Password. Do not use your account password or a WooCommerce REST API key."
> "Use HTTPS for remote HTTP connections so credentials are encrypted in transit.
> Local STDIO connections authenticate through WP-CLI using the configured `--user`
> and do not require an Application Password."

Langkah membuat Application Password: **Users → Profile → Application Passwords →
Add New Application Password**; password hanya ditampilkan sekali.
Sumber: https://developer.woocommerce.com/docs/features/mcp/

### 5.4 User khusus MCP ber-capability terbatas

Dari post resmi (Security and best practices):

> "**Use dedicated users for MCP access** — Especially in production, create a specific
> role/user with limited capabilities. Do not expose powerful abilities to unaudited AI
> clients."

Dari doc WooCommerce:

> "The default server requires an authenticated user with the `read` capability, and each
> WooCommerce ability also enforces its own permission callback. Use a dedicated WordPress
> user with only the capabilities the MCP client needs."

Praktik untuk plugin kami: buat user khusus (peran editor/shop-manager terbatas atau peran
kustom), capability minimal `read` untuk default server + capability yang dicek
`permission_callback` tiap ability (mis. `manage_woocommerce` untuk operasi katalog).
Sumber: 
- https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/
- https://developer.woocommerce.com/docs/features/mcp/

---

## 6. Cara uji tanpa Claude

### 6.1 WP-CLI (STDIO — cara tercepat di VPS)

Daftar semua server MCP terdaftar:

```bash
wp mcp-adapter list
wp mcp-adapter list --format=json | jq '.[].id'
```

`tools/list` pada default server (JSON-RPC via STDIO):

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | \
  wp --path=/path/to/your/wordpress/site \
  mcp-adapter serve \
  --server=mcp-adapter-default-server \
  --user=admin
```
Sumber: https://developer.woocommerce.com/docs/features/mcp/

Panggil meta-tool discovery (lihat semua ability yang terekspos):

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' | \
  wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```
Sumber: https://github.com/WordPress/mcp-adapter

Debug permission & verifikasi ability terdaftar:

```bash
wp mcp-adapter serve --user=admin --debug
wp eval "var_dump(array_keys(wp_get_abilities()));"
wp eval "var_dump(wp_get_ability('my-plugin/my-ability'));"
wp rest list | grep mcp
```
Sumber: 
- https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/cli-usage.md
- https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md

Sintaks lengkap: `wp mcp-adapter serve [--server=<server-id>] [--user=<id|login|email>]`;
tanpa `--user` berjalan sebagai unauthenticated (capability terbatas).
Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/cli-usage.md

### 6.2 HTTP curl dengan Application Password (dari VPS)

**Langkah 1 — handshake `initialize`, tangkap `Mcp-Session-Id` dari response header
(`-D -` mencetak header):**

```bash
curl -s -D - -X POST "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"my-client","version":"1.0.0"}}}'
```

**Langkah 2 — semua request setelah `initialize` WAJIB membawa header `Mcp-Session-Id`:**

```bash
# Correct — include the session header received from initialize
curl -s -X POST "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: <session-id-from-initialize>" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

Tanpa header session → error JSON-RPC `-32600` "Missing Mcp-Session-Id header".
Session idle timeout default 24 jam; dapat diubah via filter
`mcp_adapter_session_inactivity_timeout`.

Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md

**Cek cepat 401 vs 200 (perilaku endpoint situs target):**

```bash
# Tanpa kredensial → 401 (kondisi saat ini di cf.djavaweb.com)
curl -i "https://cf.djavaweb.com/wp-json/mcp/mcp-adapter-default-server"

# Dengan Application Password → harus lolos transport permission (is_user_logged_in)
curl -i -X POST "https://cf.djavaweb.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "mcp-user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"1.0.0"}}}'
```
(Perintah pertama mengikuti pola dari troubleshooting doc: `curl "https://yoursite.com/wp-json/"`.)

**Prasyarat HTTP:** permalink **bukan** "Plain"; REST API dasar hidup.

```bash
wp option get permalink_structure
curl "https://yoursite.com/wp-json/"
```
Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md

### 6.3 MCP Inspector (opsional)

Doc instalasi adapter merekomendasikan testing via **MCP Inspector** dengan transport
STDIO (panduan langkah demi langkah ada di https://wsform.com/how-to-create-an-mcp-server-in-wordpress-with-the-abilities-api-and-mcp-adapter/ — sumber komunitas, bukan resmi).

---

## 7. Flag fitur WooCommerce `mcp_integration`

**Tidak wajib untuk plugin pihak ketiga yang hanya memakai Abilities API + MCP Adapter
default server.** Berdasar doc resmi WooCommerce:

- Flag `mcp_integration` mengontrol **integrasi MCP milik WooCommerce sendiri**
  (`MCPAdapterProvider.php`): inisialisasi adapter yang di-bundle WooCommerce dan
  pembuatan endpoint **deprecated** `/wp-json/woocommerce/mcp`.
- Ability "purpose-built" WooCommerce tetap tampil lewat **shared WordPress MCP adapter
  default server** (`/wp-json/mcp/mcp-adapter-default-server`) — ditandai dengan metadata
  bersama `mcp.public` dan `mcp.type`, bukan lewat endpoint khusus WooCommerce.
- Plugin pihak ketiga yang ingin ability-nya **ikut** masuk WooCommerce MCP server
  (deprecated) memakai filter `woocommerce_mcp_include_ability`; untuk endpoint deprecated
  juga ada meta `expose_in_deprecated_woocommerce_mcp`.

Kutipan (disalin persis):

```php
add_filter( 'woocommerce_features', function( $features ) {  
    $features['mcp_integration'] = true;  
    return $features;  
});
```

```bash
wp option update woocommerce_feature_mcp_integration_enabled yes
```

> "The WooCommerce-specific `/wp-json/woocommerce/mcp` endpoint is deprecated and should
> not be used for new integrations."
> "WooCommerce abilities are available through the WordPress MCP Adapter's default server:
> `https://yourstore.com/wp-json/mcp/mcp-adapter-default-server`"

Sumber: https://developer.woocommerce.com/docs/features/mcp/

**Kesimpulan untuk Bulk Catalog Studio:** cukup `meta.mcp.public => true` pada ability.
Jangan tambahkan dependensi pada fitur preview `mcp_integration` (status *developer
preview*, bisa berubah). Filter `woocommerce_mcp_include_ability` opsional jika ingin
ability commerce ikut server WooCommerce.

---

## 8. Contoh plugin resmi (pola kode)

### 8.1 WooCommerce MCP Ability Demo Plugin (`woocommerce/wc-mcp-ability`)

Plugin demo resmi WooCommerce: meregister ability `woocommerce-demo/store-info`
(info toko + statistik opsional), permission `manage_woocommerce`, dan memasukkan ability
ke WooCommerce MCP server via filter.

Pola kunci (dari README repo):

```php
add_action( 'abilities_api_init', array( __CLASS__, 'register_store_info_ability' ) );
```

```php
add_filter( 'woocommerce_mcp_include_ability', array( __CLASS__, 'include_demo_ability_in_mcp' ), 10, 2 );
```

Ability dipanggil dengan:

```json
{
  "ability": "woocommerce-demo/store-info",
  "input": {
    "include_stats": true
  }
}
```

Requirements repo: WordPress 6.0+, PHP 8.0+, WooCommerce 10.3+; status **developer
preview**, "DEMO TOOL — NOT PRODUCTION READY".
Sumber: https://github.com/woocommerce/wc-mcp-ability

Pola modern (dari toolkit resmi Automattic — pakai hook `wp_abilities_api_init`,
guard `function_exists`, header `Requires Plugins`):

```php
<?php
/**
 * Plugin Name: Example Commerce Abilities
 * Requires Plugins: woocommerce
 * Requires PHP:     8.1
 *
 * @package PluginSlug
 */

declare( strict_types=1 );

namespace PluginSlug;

defined( 'ABSPATH' ) || exit;

add_action( 'abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_filter( 'woocommerce_mcp_include_ability', __NAMESPACE__ . '\\include_in_mcp', 10, 2 );

function register_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability(
		'plugin-slug/store-summary',
		array(
			'label'               => __( 'Get Store Summary', 'plugin-slug' ),
			'description'         => __( 'Read-only store name, currency, and order counts.', 'plugin-slug' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => __NAMESPACE__ . '\\get_store_summary',
			'permission_callback' => static fn (): bool => current_user_can( 'manage_woocommerce' ),
		)
	);
}

function include_in_mcp( bool $include, string $ability_name ): bool {
	return 'plugin-slug/store-summary' === $ability_name ? true : $include;
}

function get_store_summary(): array {
	return array(
		'store_name'          => get_bloginfo( 'name' ),
		'currency'            => get_woocommerce_currency(),
		'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
	);
}
```
Sumber: https://github.com/Automattic/claude-woocommerce-toolkit/blob/main/skills/woocommerce-plugin-dev/references/abilities-and-mcp.md

Pola ekspose ke default adapter (dari doc WooCommerce, hook `wp_abilities_api_init`):

```php
add_action( 'wp_abilities_api_init', function() {  
    if ( ! function_exists( 'wp_register_ability' ) ) {  
        return;  
    }  
  
    wp_register_ability(  
        'your-plugin/custom-operation',  
        array(  
            'label'               => __( 'Custom Store Operation', 'your-plugin' ),  
            'description'         => __( 'Performs a custom store operation.', 'your-plugin' ),  
            'category'            => 'your-plugin',  
            'execute_callback'    => 'your_custom_ability_handler',  
            'permission_callback' => function () {  
                return current_user_can( 'manage_woocommerce' );  
            },  
            'input_schema'        => array(  
                'type'       => 'object',  
                'properties' => array(  
                    'store_id' => array(  
                        'type'        => 'integer',  
                        'description' => 'Store identifier',  
                    ),  
                ),  
                'required' => array( 'store_id' ),  
            ),  
            'output_schema' => array(  
                'type'       => 'object',  
                'properties' => array(  
                    'success' => array(  
                        'type'        => 'boolean',  
                        'description' => 'Operation result',  
                    ),  
                ),  
            ),  
            'meta'                => array(  
                'show_in_rest' => true,  
                'mcp'          => array(  
                    'public' => true,  
                    'type'   => 'tool',  
                ),  
            ),  
        )  
    );  
});
```
Sumber: https://developer.woocommerce.com/docs/features/mcp/

Filter `woocommerce_mcp_include_ability` (untuk opt-in ke server WooCommerce):

```php
add_filter( 'woocommerce_mcp_include_ability', function( $include, $ability_id ) {  
    if ( str_starts_with( $ability_id, 'your-plugin/' ) ) {  
        return true;  
    }  
    return $include;  
}, 10, 2 );
```
Sumber: https://developer.woocommerce.com/docs/features/mcp/

### 8.2 List All URLs (pola custom server)

Repo: `wptrainingteam/list-all-urls`, branch `abilities`; register ability
`list-all-urls/urls` + custom MCP server `list-all-urls-mcp-server` (kode lengkap di
bagian 3.3). Checkout:

```bash
cd wp-content/plugins
git clone git@github.com:wptrainingteam/list-all-urls.git
cd list-all-urls
git checkout abilities
composer install
composer require wordpress/mcp-adapter
```
Sumber: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/

---

## Perangkap umum (gotchas)

1. **Nama hook**: gunakan `wp_abilities_api_init` (abilities) dan
   `wp_abilities_api_categories_init` (kategori). Contoh lama/demo (wc-mcp-ability README,
   beberapa tutorial) masih menulis `abilities_api_init` — source core saat ini memvalidasi
   `doing_action( 'wp_abilities_api_init' )`; registrasi di luar hook itu mengembalikan
   `null`. Sumber: https://developer.wordpress.org/reference/functions/wp_register_ability/
2. **Kategori tidak terdaftar = gagal senyap.** `wp_register_ability()` mengembalikan
   `null` tanpa `WP_Error`; `_doing_it_wrong()` hanya tampil saat `WP_DEBUG` on. Di
   produksi, kegagalan terlihat seperti tidak terjadi apa-apa. Gunakan kategori core
   `site`/`user` atau daftarkan kategori sendiri lebih dulu.
   Sumber: https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md
3. **Case sensitivity & format nama**: ability name hanya lowercase alfanumerik, dash,
   satu garis miring (`namespace/ability-name`); slug kategori lowercase + dash saja
   (underscore = invalid). Sumber: php-api.md & creating-abilities.md.
4. **Ability private secara default.** Tanpa `meta.mcp.public`/`meta.public`, klien MCP
   berhasil connect dan `discover-abilities` mengembalikan kosong — bukan bug.
   Sumber: creating-abilities.md.
5. **Default server ≠ tools individual.** Ability public diakses via 3 meta-tools
   (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`,
   `mcp-adapter-execute-ability`), tidak muncul sendiri-sendiri di `tools/list`.
   Ingin muncul individual → custom server (tanpa perlu flag public).
   Sumber: README adapter.
6. **Sanitasi nama tool**: `my-plugin/my-tool` → `my-plugin-my-tool` di custom server
   (MCP melarang `/`, maks 128 char, charset `A-Za-z0-9_.-`); override via filter
   `mcp_adapter_tool_name`. Sumber: creating-abilities.md.
7. **HTTP wajib session**: setelah `initialize`, semua request butuh header
   `Mcp-Session-Id`; error `-32600` kalau tidak ada; idle timeout 24 jam (filter
   `mcp_adapter_session_inactivity_timeout`). Sumber: troubleshooting common-issues.md.
8. **Permalink "Plain" mematikan REST/MCP** → 404. Cek `wp option get permalink_structure`.
   Sumber: troubleshooting common-issues.md.
9. **401 di endpoint default server itu normal** untuk request tanpa kredensial (sesuai
   observasi awal di cf.djavaweb.com). Transport permission default `is_user_logged_in()`;
   autentikasi HTTP memakai Application Password (bukan password akun, bukan REST API key
   WooCommerce). Sumber: transport-permissions.md + WooCommerce docs.
10. **Konflik versi antar-plugin**: pakai Jetpack Autoloader
    (`automattic/jetpack-autoloader`, load `vendor/autoload_packages.php`) bila lebih dari
    satu plugin membundel `wordpress/mcp-adapter`. Sumber: README adapter.
11. **Zip release vs source checkout**: zip GitHub Releases sudah termasuk `vendor/`;
    source checkout butuh `composer install` dulu, kalau tidak muncul error
    "The Composer autoloader was not found". Sumber: troubleshooting common-issues.md.
12. **Versi minimum**: adapter butuh PHP >= 7.4 + WP >= 6.9; praktik plugin baru: PHP 8.x,
    WP 6.9+; WooCommerce MCP = developer preview (bisa berubah). Ability API repo GitHub
    (`WordPress/abilities-api`) **sudah diarsipkan (5 Feb 2026)** karena API masuk core —
    jangan meng-install plugin abilities-api terpisah di WP 7.1.1.
    Sumber: README adapter + banner arsip di repo.
13. **Flattened schema**: input `{'type': 'string'}` otomatis dibungkus jadi
    `{type:'object', properties:{input:{...}}, required:['input']}` — callback menerima
    nilai **unwrapped** (`'post'`, bukan `['input'=>'post']`); output dibungkus `{result:…}`.
    Sumber: creating-abilities.md.
14. **`meta.public` vs `meta.show_in_rest` di WP 7.x**: sejak WP 7.1 `meta.public` ikut
    men-seed REST; di 6.9/7.0 REST butuh `show_in_rest` eksplisit. Di WP 7.1.1 (situs
    target) `meta.public => true` cukup untuk REST + MCP; tetap boleh set keduanya eksplisit
    untuk kejelasan. Sumber: creating-abilities.md.
15. **Jangan `__return_true` untuk ability destruktif**; `permission_callback` berjalan di
    setiap invokasi; validasi input di `execute_callback` tetap diperlukan (schema bukan
    pengganti validasi server-side); jangan pernah mengekspos secret (API key gateway).
    Sumber: post developer.wordpress.org + claude-woocommerce-toolkit.
16. **mcp-wordpress-remote**: `WP_API_URL` harus URL endpoint penuh (domain telanjang =
    endpoint legacy `wordpress-mcp` yang deprecated); `WP_API_USERNAME` = username login
    (bukan email); OAuth default aktif → set `OAUTH_ENABLED=false` untuk Application
    Password; masalah umum = versi Node ganda & SSL lokal (`NODE_EXTRA_CA_CERTS`).
    Sumber: https://github.com/Automattic/mcp-wordpress-remote
17. **`Requires Plugins: mcp-adapter`** di header plugin bila plugin bergantung pada plugin
    adapter (memastikan urutan load). Sumber: troubleshooting common-issues.md.

---

## Daftar URL sumber yang berhasil di-fetch

1. https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/
2. https://developer.woocommerce.com/docs/features/mcp/
3. https://github.com/Automattic/claude-woocommerce-toolkit/blob/main/skills/woocommerce-plugin-dev/references/abilities-and-mcp.md
4. https://github.com/WordPress/mcp-adapter (README)
5. https://developer.wordpress.org/reference/functions/wp_register_ability/
6. https://github.com/WordPress/abilities-api/blob/trunk/docs/php-api.md
7. https://github.com/Automattic/mcp-wordpress-remote (README)
8. https://github.com/woocommerce/wc-mcp-ability (README)
9. https://developer.wordpress.org/apis/abilities-api/ (Common APIs Handbook)
10. https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md
11. https://github.com/WordPress/mcp-adapter/tree/trunk/docs (indeks dokumentasi)
12. https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/cli-usage.md
13. https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/transport-permissions.md
14. https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md
