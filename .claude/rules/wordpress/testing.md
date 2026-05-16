---
paths:
  - "**/*.php"
  - "**/phpunit.xml"
  - "**/phpunit.xml.dist"
  - "**/composer.json"
  - "**/tests/**"
---
# WordPress Testing

## Framework

Use **PHPUnit** with the **WordPress test suite** (`wp-env` provides it automatically). The base class is `WP_UnitTestCase` for integration tests and `WP_Ajax_UnitTestCase` for AJAX handlers.

For unit tests that don't need a full WordPress bootstrap, use **Brain Monkey** or plain PHPUnit with mocks.

## Running Tests

```bash
# With wp-env (recommended)
npm run env:start
wp-env run tests-phpunit -- phpunit

# Direct phpunit (if WordPress test suite is bootstrapped locally)
./vendor/bin/phpunit

# Single file
wp-env run tests-phpunit -- phpunit tests/phpunit/test-my-class.php
```

## Directory Structure

```
tests/
└── phpunit/
    ├── bootstrap.php          # Test bootstrap
    ├── test-plugin-core.php   # Integration tests
    ├── test-admin.php
    ├── test-rest-api.php
    └── unit/
        └── test-utils.php     # Unit tests (no WP bootstrap needed)
```

## Integration Test Pattern

```php
<?php
declare(strict_types=1);

class Test_MyPlugin_Core extends WP_UnitTestCase {

    private MyPlugin_Core $instance;

    public function set_up(): void {
        parent::set_up();
        $this->instance = new MyPlugin_Core();
    }

    public function test_register_post_type_creates_cpt(): void {
        $this->instance->register_post_types();
        $this->assertTrue( post_type_exists( 'myplugin_project' ) );
    }

    public function test_save_post_sanitizes_meta(): void {
        $post_id = self::factory()->post->create( [ 'post_type' => 'myplugin_project' ] );

        // Simulate POST data
        $_POST['_wpnonce']        = wp_create_nonce( 'myplugin_save_meta' );
        $_POST['myplugin_url']    = 'https://example.com/<script>';

        $this->instance->save_post_meta( $post_id );

        $saved = get_post_meta( $post_id, '_myplugin_url', true );
        $this->assertSame( 'https://example.com/', $saved );
        $this->assertStringNotContainsString( '<script>', $saved );
    }
}
```

## AJAX Test Pattern

```php
class Test_MyPlugin_Ajax extends WP_Ajax_UnitTestCase {

    public function test_ajax_handler_requires_nonce(): void {
        $this->expectException( WPAjaxDieStopException::class );
        $this->_handleAjax( 'myplugin_do_thing' );
    }

    public function test_ajax_handler_returns_data(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
        wp_set_current_user( $user_id );

        $_POST['_ajax_nonce'] = wp_create_nonce( 'myplugin_ajax' );
        $_POST['item_id']     = '42';

        try {
            $this->_handleAjax( 'myplugin_do_thing' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected — AJAX handler called wp_send_json_success()
        }

        $response = json_decode( $this->_last_response, true );
        $this->assertTrue( $response['success'] );
    }
}
```

## REST API Test Pattern

```php
class Test_MyPlugin_REST extends WP_Test_REST_TestCase {

    private WP_REST_Server $server;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function test_endpoint_returns_200_for_public(): void {
        $request  = new WP_REST_Request( 'GET', '/myplugin/v1/projects' );
        $response = $this->server->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_create_endpoint_requires_auth(): void {
        $request  = new WP_REST_Request( 'POST', '/myplugin/v1/projects' );
        $response = $this->server->dispatch( $request );

        $this->assertSame( 401, $response->get_status() );
    }
}
```

## WP_Query and Factory Usage

Use factories for test data — never rely on fixtures in the database:

```php
// Create posts
$post_id = self::factory()->post->create( [
    'post_type'   => 'myplugin_project',
    'post_status' => 'publish',
    'post_title'  => 'Test Project',
] );

// Create users with roles
$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
wp_set_current_user( $admin_id );

// Create terms
$term = self::factory()->term->create_and_get( [
    'taxonomy' => 'category',
    'name'     => 'Test Category',
] );
```

## Unit Tests (no WordPress bootstrap)

For pure utility functions, use Brain Monkey to mock WordPress functions without a full bootstrap:

```php
use Brain\Monkey;
use Brain\Monkey\Functions;

class Test_MyPlugin_Utils extends \PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_format_price_sanitizes_input(): void {
        Functions\expect( 'number_format_i18n' )
            ->once()
            ->with( 9.99, 2 )
            ->andReturn( '9.99' );

        $result = myplugin_format_price( '9.99' );
        $this->assertSame( '$9.99', $result );
    }
}
```

## Coverage Requirements

- Minimum **80% line coverage** for `includes/`
- All security-sensitive paths (nonce checks, capability checks, sanitization) must have tests
- All REST endpoints must have auth and happy-path tests
- All meta save/retrieve operations must have sanitization tests

```xml
<!-- phpunit.xml -->
<coverage>
    <include>
        <directory suffix=".php">./includes</directory>
    </include>
    <report>
        <text outputFile="php://stdout" showOnlySummary="true"/>
    </report>
</coverage>
```

## phpunit.xml Template

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="tests/phpunit/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>./tests/phpunit/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>./tests/phpunit</directory>
            <exclude>./tests/phpunit/unit</exclude>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">./includes</directory>
        </include>
    </coverage>
</phpunit>
```
