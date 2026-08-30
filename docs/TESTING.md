# Testing Guide

## Current Test Coverage

The extension has a PHPUnit test framework with fixtures.

**There IS a CI pipeline, and the unit suite is its blocking gate** (`.github/workflows/ci.yml`,
since PR #22). `lint` and `analyze` run `continue-on-error`; `composer run test:unit` does not, so
a red unit suite blocks the PR. This paragraph used to say "no CI/CD pipeline is in place" — it was
stale, and believing it is how a CI-only failure comes as a surprise.

**CI has no CiviCRM.** It runs `composer install` and nothing else, so `tests/bootstrap.php` cannot
find `civicrm.config.php`, prints `Warning: Could not find CiviCRM bootstrap`, and every test
needing a bootstrapped Civi self-skips. Two consequences worth knowing before writing a test:

- **The Integration suite never actually runs in CI.** Security-critical behaviour is asserted
  instead by `cv scr` scripts under `tests/Security/`, against a live site.
- **Never put `@covers` on a CiviCRM-dependent class.** PHPUnit resolves the named class to build
  its coverage map, which autoloads it; anything extending `AutoSubscriber` or touching `CRM_*`
  then dies with `Class "Civi\Core\Service\AutoSubscriber" not found` and aborts the WHOLE suite
  before a test runs. It passes locally, because this checkout sits inside buildkit where the
  bootstrap does find CiviCRM. Use `@coversNothing` (the config sets
  `beStrictAboutCoversAnnotation="true"`, so it is the supported way to say so), and reserve
  `@covers` for classes that load without CiviCRM.

To reproduce a CI-only failure locally, `git archive` the branch into a directory **outside**
buildkit and run it there — overriding the bootstrap with `--bootstrap vendor/autoload.php` does
NOT reproduce it, because that skips the real bootstrap and passes.

### Test Structure

```
tests/
├── Unit/                            # runs in CI — no CiviCRM available here
│   ├── Util/CodeGeneratorTest.php   # MAS code generation (R25xxx, P25xxx)
│   ├── Service/                     # lifecycle queue reporting
│   └── Security/                    # afform arg policy + guard wiring tripwire
├── Integration/                     # self-skips in CI (needs a bootstrapped Civi)
│   ├── CiviRules/
│   └── Managed/
├── Security/                        # NOT a phpunit suite — `cv scr` + shell, live site
│   ├── CaseDetailAccessTest.php     # VC Portal case-detail entitlement
│   ├── AfformPublicArgGuardTest.php # public-afform arg entitlement (task #159)
│   └── afform-prefill-anon-probe.sh # anonymous HTTP probe; safe against production
├── Live/                            # NOT a phpunit suite — `cv scr`, live site, non-security
│   └── ClientRepChangeTest.php      # client-rep change on the two VC project forms
├── Fixtures/
├── TestCase.php                     # Base test class
└── bootstrap.php                    # Test environment setup
```

`tests/Security/` is deliberately outside the phpunit testsuites: those scripts need a live,
fully-bootstrapped CiviCRM (and, for the probe, real HTTP with no session), which neither the CI
runner nor the Integration suite can provide. Run them by hand — each file's docblock says how.

`tests/Live/` is the same mechanism for behaviour that is not a security boundary. The split is by
what the test is FOR, not by how it runs: a reader scanning `tests/Security/` should find the
access-control assertions and nothing else. Both directories are outside the phpunit testsuites for
the same reason, and both are run by hand — each file's docblock says how.

Where behaviour needs a live site, prefer the pair used by the client-rep change: a `tests/Live/`
script that proves the behaviour, plus a small `tests/Unit/` source tripwire pinning the invariants
CI can still see. The live script is the real proof and CI cannot run it; the tripwire's only job is
to catch the silent removal of something load-bearing. Keep the tripwire narrow — it is
source-matching, which is a blunt instrument, and it earns its place only because the alternative is
no CI signal at all.

## Running Tests

### Prerequisites

```bash
composer install
cv ext:enable mascode
```

### Commands

```bash
# Run all tests
composer test

# Run specific suites
composer test:unit
composer test:integration

# Run with coverage
composer test:coverage

# Run individual test
./vendor/bin/phpunit tests/Unit/Util/CodeGeneratorTest.php

# Run with XDebug
XDEBUG_SESSION=1 ./vendor/bin/phpunit tests/Unit/Util/CodeGeneratorTest.php
```

## Test Fixtures

### ContactFixture

```php
$contact = ContactFixture::create();
$masRep = ContactFixture::createWithRole('mas_rep');
$org = ContactFixture::createOrganization();
```

### CaseFixture

```php
$serviceRequest = CaseFixture::createServiceRequest($clientId);
$project = CaseFixture::createProject($clientId);
$scenario = CaseFixture::createCompleteScenario('service_request');
```

## Writing New Tests

### Unit Tests

Fast, isolated, no database. Use mocks for CiviCRM dependencies.

```php
public function testGenerateServiceRequestCode(): void
{
    $code = $this->codeGenerator->generateCode('service_request');
    $this->assertMatchesRegularExpression('/^R\d{5}$/', $code);
}
```

### Integration Tests

Use real CiviCRM database. Clean up after each test.

```php
protected function setUp(): void
{
    parent::setUp();
    $this->skipIfNoDatabase();
}
```

## Manual Testing Checklist

For changes without automated test coverage:

- [ ] Extension enables without errors (`cv ext:enable mascode`)
- [ ] Cache clears without issues (`cv flush`)
- [ ] No PHP errors or warnings in logs
- [ ] CiviRules actions register properly
- [ ] Forms render and submit successfully
- [ ] Anonymous form access works (if applicable)

---

*For implementation examples, see the test files in the `tests/` directory.*
