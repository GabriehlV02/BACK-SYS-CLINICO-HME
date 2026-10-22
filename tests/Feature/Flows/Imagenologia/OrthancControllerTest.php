<?php

namespace Tests\Feature\Flows\Imagenologia;

use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrthancControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_eliminar_estudio_invalida_la_lista_en_cache(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $id = '8d1f7c2a-8b11-4f45-b988-1439d3ae24ab';
        Cache::store('file')->put('orthanc.estudios', [['id' => $id]], 60);
        Http::fake(['*' => Http::response([], 200)]);

        $this->deleteJson("/api/orthanc/estudios/{$id}")->assertNoContent();

        $this->assertFalse(Cache::store('file')->has('orthanc.estudios'));
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), "/studies/{$id}"));
    }
}
