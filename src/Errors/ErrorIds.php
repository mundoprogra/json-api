<?php

  namespace MundoProgra\JsonApi\Errors;

  use Illuminate\Contracts\Foundation\Application;
  use Illuminate\Http\Request;
  use Illuminate\Support\Str;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Settings;
  use Throwable;

  /**
   * Da a cada error un identificador único y lo entrega en dos lugares: la respuesta y el contexto del log (`error_id`).
   * El paquete nunca escribe ni guarda logs: solo agrega el dato a la línea que Laravel ya va a escribir.
   * Gives every error a unique identifier and hands it over in two places: the response and the log context (`error_id`).
   * The package never writes nor stores logs: it only adds the value to the line Laravel is about to write anyway.
   */
  final class ErrorIds {
    private const ATTRIBUTE = 'json-api.error_id';

    private const CONTEXT_KEY = 'error_id';

    public function __construct(
      private readonly Settings $settings,
      private readonly Application $container,
    ) {
    }

    /**
     * Laravel escribe el log antes de armar la respuesta, así que el id se crea aquí, al reportar la excepción:
     * se agrega al contexto del log y se guarda en la petición para que la respuesta lleve el mismo.
     * Laravel writes the log before building the response, so the id is created here, when the exception is reported:
     * it is added to the log context and kept on the request so the response carries the same one.
     */
    public function reported(Throwable $e): void {
      // En consola (comandos, colas) no hay respuesta que lleve el id, y el contexto quedaría pegado a todo lo que se escriba después.
      // In the console (commands, queues) there is no response to carry the id, and the context would stick to everything written afterwards.
      if (!$this->settings->includeErrorId() || ($this->container->runningInConsole() && !$this->container->runningUnitTests())) {
        return;
      }

      // Un id que el desarrollador puso a mano en el primer error se respeta.
      // An id the developer set by hand on the first error is respected.
      $id = ($e instanceof JsonApiException ? ($e->errors()[0] ?? null)?->id() : null) ?? $this->generate();

      $this->container->make('request')->attributes->set(self::ATTRIBUTE, $id);
      $this->container->make('log')->withContext([self::CONTEXT_KEY => $id]);
    }

    /**
     * Completa el id de cada error que no lo tenga. El primero toma el que se anotó al reportar, si lo hubo; los demás reciben uno nuevo.
     * Una vez entregado, el dato se quita del contexto del log para que no se pegue a lo que se escriba después.
     * Fills in the id of every error that lacks one. The first takes the one noted when reporting, if any; the rest get a new one.
     * Once handed over, the value is removed from the log context so it does not stick to whatever is written afterwards.
     *
     * @param  list<Error>  $errors
     * @return list<Error>
     */
    public function assign(array $errors, Request $request): array {
      if (!$this->settings->includeErrorId()) {
        return $errors;
      }

      $reported = $request->attributes->get(self::ATTRIBUTE);

      $assigned = [];
      foreach ($errors as $index => $error) {
        $assigned[] = $error->id() !== null ? $error : $error->withId($index === 0 && is_string($reported) ? $reported : $this->generate());
      }

      if ($reported !== null) {
        $request->attributes->remove(self::ATTRIBUTE);
        $this->container->make('log')->withoutContext([self::CONTEXT_KEY]);
      }

      return $assigned;
    }

    private function generate(): string {
      return (string) Str::ulid();
    }
  }
