<?php
namespace Tests\Feature;

use App\Http\Middleware\VerificarSesionCompartida;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstrumentalQuirurgicoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        Schema::dropIfExists('instrumental_quirurgico');
        Schema::create('instrumental_quirurgico', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->string('codigo')->unique(); $t->string('nombre'); $t->string('lote')->nullable();
            $t->integer('cantidad'); $t->text('descripcion')->nullable(); $t->string('ubicacion'); $t->string('estado'); $t->string('imagen_ruta')->nullable(); $t->timestamps();
        });
        Storage::fake('instrumental');
    }

    public function test_guarda_imagen_en_carpeta_y_registra_su_ruta(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $foto = UploadedFile::fake()->createWithContent('foto.png', $png);
        $respuesta = $this->postJson('/api/v1/instrumental', ['codigo' => 'inst-900', 'nombre' => 'Pinza', 'cantidad' => 2, 'imagen' => $foto])->assertCreated();
        $id = $respuesta->json('data.id');
        $ruta = $respuesta->json('data.imagen_ruta');
        $this->assertStringStartsWith('imagenes instrumental/', $ruta);
        Storage::disk('instrumental')->assertExists($ruta);
        $this->assertDatabaseHas('instrumental_quirurgico', ['id' => $id, 'codigo' => 'INST-900', 'ubicacion' => 'Almacén esterilización']);
        $this->get('/api/v1/instrumental/'.$id.'/imagen')->assertOk();
        $this->postJson('/api/v1/instrumental', ['codigo' => 'inst-900', 'nombre' => 'Otro', 'cantidad' => 1])->assertUnprocessable();
        $this->assertSame(1, DB::table('instrumental_quirurgico')->count());
    }
    public function test_actualiza_solo_la_cantidad_y_rechaza_valores_invalidos(): void
    {
        $id = $this->postJson('/api/v1/instrumental', ['codigo' => 'PRUEBA', 'nombre' => 'Pinza', 'cantidad' => 3])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/instrumental/'.$id.'/cantidad', ['cantidad' => 0, 'nombre' => 'No debe cambiar'])->assertOk()->assertJsonPath('data.cantidad', 0)->assertJsonPath('data.nombre', 'Pinza');
        $this->patchJson('/api/v1/instrumental/'.$id.'/cantidad', ['cantidad' => -1])->assertUnprocessable();
        $this->patchJson('/api/v1/instrumental/'.$id.'/cantidad', ['cantidad' => 1.5])->assertUnprocessable();
        $this->assertDatabaseHas('instrumental_quirurgico', ['id' => $id, 'cantidad' => 0, 'nombre' => 'Pinza']);
        $this->patchJson('/api/v1/instrumental/11111111-1111-4111-8111-111111111111/cantidad', ['cantidad' => 2])->assertNotFound();
    }

}
