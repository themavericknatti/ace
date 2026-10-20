<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Validator;

class ValidatorTest extends TestCase
{
    public function test_required_rule(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('name', $v->errors());

        $v2 = Validator::make(['name' => 'John'], ['name' => 'required']);
        $this->assertTrue($v2->passes());
    }

    public function test_email_rule(): void
    {
        $v = Validator::make(['email' => 'bad'], ['email' => 'required|email']);
        $this->assertTrue($v->fails());

        $v2 = Validator::make(['email' => 'john@test.com'], ['email' => 'required|email']);
        $this->assertTrue($v2->passes());
    }

    public function test_min_and_max_rules(): void
    {
        $v = Validator::make(['name' => 'ab'], ['name' => 'required|min:3']);
        $this->assertTrue($v->fails());

        $v2 = Validator::make(['name' => 'abc'], ['name' => 'required|min:3']);
        $this->assertTrue($v2->passes());

        $v3 = Validator::make(['bio' => str_repeat('x', 300)], ['bio' => 'max:255']);
        $this->assertTrue($v3->fails());
    }

    public function test_in_rule(): void
    {
        $v = Validator::make(['role' => 'superadmin'], ['role' => 'required|in:admin,user,editor']);
        $this->assertTrue($v->fails());

        $v2 = Validator::make(['role' => 'admin'], ['role' => 'required|in:admin,user,editor']);
        $this->assertTrue($v2->passes());
    }

    public function test_numeric_and_integer_rules(): void
    {
        $v = Validator::make(['age' => 'abc'], ['age' => 'required|numeric']);
        $this->assertTrue($v->fails());

        $v2 = Validator::make(['age' => '25'], ['age' => 'required|integer']);
        $this->assertTrue($v2->passes());
    }

    public function test_confirmed_rule(): void
    {
        $v = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'wrong'],
            ['password' => 'required|confirmed']
        );
        $this->assertTrue($v->fails());

        $v2 = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password' => 'required|confirmed']
        );
        $this->assertTrue($v2->passes());
    }

    public function test_pipe_and_array_syntax(): void
    {
        // Pipe syntax
        $v1 = Validator::make(['email' => 'test@example.com'], ['email' => 'required|email|max:255']);
        $this->assertTrue($v1->passes());

        // Array syntax
        $v2 = Validator::make(['email' => 'test@example.com'], ['email' => ['required', 'email', 'max:255']]);
        $this->assertTrue($v2->passes());
    }

    public function test_validated_returns_only_passed_fields(): void
    {
        $v = Validator::make(
            ['name' => 'John', 'email' => 'john@test.com', 'extra' => 'ignored'],
            ['name' => 'required', 'email' => 'required|email']
        );
        $this->assertTrue($v->passes());

        $validated = $v->validated();
        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayHasKey('email', $validated);
        $this->assertArrayNotHasKey('extra', $validated);
    }

    public function test_custom_messages(): void
    {
        $v = Validator::make(
            ['email' => ''],
            ['email' => 'required'],
            ['email.required' => 'We need your email address!']
        );
        $this->assertTrue($v->fails());
        $this->assertEquals('We need your email address!', $v->first('email'));
    }

    public function test_custom_attribute_names(): void
    {
        $v = Validator::make(
            ['dob' => ''],
            ['dob' => 'required'],
            [],
            ['dob' => 'Date of Birth']
        );
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('Date of Birth', $v->first('dob'));
    }

    public function test_optional_fields_skipped_when_empty(): void
    {
        $v = Validator::make(
            ['name' => 'John', 'bio' => ''],
            ['name' => 'required', 'bio' => 'max:255']
        );
        $this->assertTrue($v->passes());
    }

    public function test_first_errors_returns_one_per_attribute(): void
    {
        $v = Validator::make(
            ['name' => '', 'email' => 'bad'],
            ['name' => 'required|min:3', 'email' => 'required|email']
        );
        $this->assertTrue($v->fails());

        $first = $v->firstErrors();
        $this->assertCount(2, $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('email', $first);
    }

    public function test_url_and_date_rules(): void
    {
        $v = Validator::make(['site' => 'not-a-url'], ['site' => 'required|url']);
        $this->assertTrue($v->fails());

        $v2 = Validator::make(['site' => 'https://example.com'], ['site' => 'required|url']);
        $this->assertTrue($v2->passes());

        $v3 = Validator::make(['when' => 'not-date'], ['when' => 'required|date']);
        $this->assertTrue($v3->fails());

        $v4 = Validator::make(['when' => '2026-01-01'], ['when' => 'required|date']);
        $this->assertTrue($v4->passes());
    }

    public function test_same_and_different_rules(): void
    {
        $v = Validator::make(
            ['a' => 'hello', 'b' => 'world'],
            ['a' => 'same:b']
        );
        $this->assertTrue($v->fails());

        $v2 = Validator::make(
            ['a' => 'hello', 'b' => 'hello'],
            ['a' => 'same:b']
        );
        $this->assertTrue($v2->passes());

        $v3 = Validator::make(
            ['a' => 'hello', 'b' => 'hello'],
            ['a' => 'different:b']
        );
        $this->assertTrue($v3->fails());
    }
}
