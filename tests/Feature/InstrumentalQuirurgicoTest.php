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
            $t->string('medida')->nullable(); $t->boolean('cuenta_con_logo')->default(false); $t->integer('cantidad'); $t->text('descripcion')->nullable(); $t->string('ubicacion'); $t->string('estado'); $t->string('imagen_ruta')->nullable(); $t->timestamps();
        });
        Storage::fake('instrumental');
    }

    public function test_guarda_imagen_en_carpeta_y_registra_su_ruta(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $foto = UploadedFile::fake()->createWithContent('foto.png', $png);
        $respuesta = $this->postJson('/api/v1/instrumental', ['codigo' => 'inst-900', 'nombre' => 'Pinza', 'medida' => '14 cm', 'cuenta_con_logo' => true, 'cantidad' => 2, 'imagen' => $foto])->assertCreated();
        $id = $respuesta->json('data.id');
        $ruta = $respuesta->json('data.imagen_ruta');
        $this->assertStringStartsWith('imagenes instrumental/', $ruta);
        Storage::disk('instrumental')->assertExists($ruta);
        $this->assertDatabaseHas('instrumental_quirurgico', ['id' => $id, 'codigo' => 'INST-900', 'medida' => '14 cm', 'cuenta_con_logo' => true, 'lote' => null, 'ubicacion' => 'Almacén esterilización']);
        $this->get('/api/v1/instrumental/'.$id.'/imagen')->assertOk();
        $this->postJson('/api/v1/instrumental', ['codigo' => 'inst-900', 'nombre' => 'Otro', 'cantidad' => 1])->assertUnprocessable();
        $this->assertSame(1, DB::table('instrumental_quirurgico')->count());
    }
    public function test_actualiza_solo_la_cantidad_y_rechaza_valores_invalidos(): void
    {
        $id = $this->postJson('/api/v1/instrumental', ['codigo' => 'PRUEBA', 'nombre' => 'Pinza', 'cantidad' => 3])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/instrumental/'.$id.'/cantidad', ['cantidad' => 0, 'nombre' => 'No debe cambiar'])->assertOk()->assertJsonPath('data.cantidad', 0)->assertJsonPath('data.nombre', 'Pinza')->assertJsonPath('data.cuenta_con_logo', 0);
        $this->patchJson('/api/v1/instrumental/'.$id.'/cantidad', ['cantidad' => -1])->assertUnprocessable();
        $this->patchJson('/api/v1/instrumental/'.$id.'/cantidad', ['cantidad' => 1.5])->assertUnprocessable();
        $this->assertDatabaseHas('instrumental_quirurgico', ['id' => $id, 'cantidad' => 0, 'nombre' => 'Pinza']);
        $this->patchJson('/api/v1/instrumental/11111111-1111-4111-8111-111111111111/cantidad', ['cantidad' => 2])->assertNotFound();
    }

    public function test_edita_datos_y_conserva_la_imagen_existente(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $foto = UploadedFile::fake()->createWithContent('foto.png', $png);
        $creado = $this->postJson('/api/v1/instrumental', ['codigo' => 'INST-1', 'nombre' => 'Pinza', 'cantidad' => 2, 'imagen' => $foto])->assertCreated();
        $id = $creado->json('data.id');
        $ruta = $creado->json('data.imagen_ruta');
        $datos = ['codigo' => 'inst-1', 'nombre' => 'Pinza curva', 'medida' => '14 cm', 'lote' => 'L-1', 'cuenta_con_logo' => true, 'cantidad' => 0, 'descripcion' => 'Revisada'];
        $this->patchJson('/api/v1/instrumental/'.$id, $datos)->assertOk()->assertJsonPath('data.nombre', 'Pinza curva')->assertJsonPath('data.cantidad', 0)->assertJsonPath('data.imagen_ruta', $ruta);
        $this->assertDatabaseHas('instrumental_quirurgico', ['id' => $id, 'codigo' => 'INST-1', 'lote' => 'L-1', 'medida' => '14 cm', 'cuenta_con_logo' => true]);
        Storage::disk('instrumental')->assertExists($ruta);
        $otro = $this->postJson('/api/v1/instrumental', ['codigo' => 'INST-2', 'nombre' => 'Otro', 'cantidad' => 1])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/instrumental/'.$otro, [...$datos, 'codigo' => 'inst-1'])->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->patchJson('/api/v1/instrumental/'.$id, [...$datos, 'cantidad' => -1])->assertUnprocessable()->assertJsonValidationErrors('cantidad');
        $this->patchJson('/api/v1/instrumental/11111111-1111-4111-8111-111111111111', $datos)->assertNotFound();
    }

    public function test_reemplaza_y_elimina_la_imagen_del_material(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $creado = $this->postJson('/api/v1/instrumental', ['codigo' => 'INST-3', 'nombre' => 'Pinza', 'cantidad' => 1, 'imagen' => UploadedFile::fake()->createWithContent('foto.png', $png)])->assertCreated();
        $id = $creado->json('data.id');
        $anterior = $creado->json('data.imagen_ruta');
        $datos = ['codigo' => 'INST-3', 'nombre' => 'Pinza', 'cuenta_con_logo' => false, 'cantidad' => 1];
        $nueva = $this->post('/api/v1/instrumental/'.$id, [...$datos, '_method' => 'PATCH', 'imagen' => UploadedFile::fake()->createWithContent('nueva.png', $png)])->assertOk()->json('data.imagen_ruta');
        $this->assertNotSame($anterior, $nueva);
        Storage::disk('instrumental')->assertMissing($anterior);
        Storage::disk('instrumental')->assertExists($nueva);
        $this->patchJson('/api/v1/instrumental/'.$id, [...$datos, 'eliminar_imagen' => true])->assertOk()->assertJsonPath('data.imagen_ruta', null);
        Storage::disk('instrumental')->assertMissing($nueva);
    }

}
