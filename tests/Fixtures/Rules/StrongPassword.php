<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Rules;

  use Closure;
  use Illuminate\Contracts\Validation\ValidationRule;

  /**
   * Un ejemplo de regla de validación propia, para comprobar que su código se saca del nombre de la clase.
   * An example of an own validation rule, to check that its code comes from the class name.
   */
  class StrongPassword implements ValidationRule {
    public function validate(string $attribute, mixed $value, Closure $fail): void {
      if (strlen((string) $value) < 8) {
        $fail('The password is too weak.');
      }
    }
  }
