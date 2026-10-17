<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  /**
   * Un recurso que declara el nombre corto de su ruta de consulta (`authors.show`): de ahí sale su `links.self`.
   * A resource that declares the short name of its show route (`authors.show`): its `links.self` comes from there.
   */
  class LinkedAuthorResource extends AuthorResource {
    protected function jsonApiRoute(): ?string {
      return 'authors.show';
    }
  }
