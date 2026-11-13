<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstrumentalQuirurgicoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('instrumental_quirurgico')->orderBy('nombre')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['codigo' => Str::upper(trim((string) $request->input('codigo')))]);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:80', Rule::unique('instrumental_quirurgico', 'codigo')],
            'nombre' => ['required', 'string', 'max:180'],
            'lote' => ['nullable', 'string', 'max:100'],
            'medida' => ['nullable', 'string', 'max:100'],
            'cuenta_con_logo' => ['sometimes', 'boolean'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $ruta = null;
        try {
            if ($request->hasFile('imagen')) {
                $ruta = $request->file('imagen')->store('imagenes instrumental', 'instrumental');
                if (! $ruta) throw new \RuntimeException('No se pudo guardar la imagen.');
            }
            $id = (string) Str::uuid();
            DB::table('instrumental_quirurgico')->insert([
                'id' => $id, 'codigo' => Str::upper(trim($data['codigo'])), 'nombre' => trim($data['nombre']),
                'lote' => $data['lote'] ?? null, 'medida' => $data['medida'] ?? null, 'cuenta_con_logo' => $request->boolean('cuenta_con_logo'), 'cantidad' => $data['cantidad'],
                'descripcion' => $data['descripcion'] ?? null, 'ubicacion' => 'Almacén esterilización',
                'estado' => 'pendiente', 'imagen_ruta' => $ruta, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['data' => DB::table('instrumental_quirurgico')->where('id', $id)->first()], 201);
        } catch (\Throwable $error) {
            if ($ruta) Storage::disk('instrumental')->delete($ruta);
            throw $error;
        }
    }

    public function actualizarCantidad(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate(['cantidad' => ['required', 'integer', 'min:0', 'max:1000000']]);
        $actualizados = DB::table('instrumental_quirurgico')->where('id', $id)->update([
            'cantidad' => $datos['cantidad'], 'updated_at' => now(),
        ]);
        abort_unless($actualizados, 404);

        return response()->json(['data' => DB::table('instrumental_quirurgico')->where('id', $id)->first()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        abort_unless(DB::table('instrumental_quirurgico')->where('id', $id)->exists(), 404);
        $request->merge(['codigo' => Str::upper(trim((string) $request->input('codigo'))), 'nombre' => trim((string) $request->input('nombre'))]);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:80', Rule::unique('instrumental_quirurgico', 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'max:180'],
            'lote' => ['nullable', 'string', 'max:100'],
            'medida' => ['nullable', 'string', 'max:100'],
            'cuenta_con_logo' => ['required', 'boolean'],
            'cantidad' => ['required', 'integer', 'min:0', 'max:1000000'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'eliminar_imagen' => ['sometimes', 'boolean'],
        ]);

        $nuevaRuta = null;
        try {
            if ($request->hasFile('imagen')) {
                $nuevaRuta = $request->file('imagen')->store('imagenes instrumental', 'instrumental');
                if (! $nuevaRuta) throw new \RuntimeException('No se pudo guardar la imagen.');
            }
            $rutaAnterior = DB::transaction(function () use ($id, $data, $request, $nuevaRuta) {
                $actual = DB::table('instrumental_quirurgico')->where('id', $id)->lockForUpdate()->first();
                abort_unless($actual, 404);
                $ruta = $nuevaRuta ?: ($request->boolean('eliminar_imagen') ? null : $actual->imagen_ruta);
                DB::table('instrumental_quirurgico')->where('id', $id)->update([
                    'codigo' => $data['codigo'], 'nombre' => $data['nombre'], 'lote' => $data['lote'] ?? null,
                    'medida' => $data['medida'] ?? null, 'cuenta_con_logo' => $request->boolean('cuenta_con_logo'),
                    'cantidad' => $data['cantidad'], 'descripcion' => $data['descripcion'] ?? null,
                    'imagen_ruta' => $ruta, 'updated_at' => now(),
                ]);
                return $ruta !== $actual->imagen_ruta ? $actual->imagen_ruta : null;
            });
        } catch (\Throwable $error) {
            if ($nuevaRuta) Storage::disk('instrumental')->delete($nuevaRuta);
            throw $error;
        }
        if ($rutaAnterior && Str::startsWith($rutaAnterior, 'imagenes instrumental/')) Storage::disk('instrumental')->delete($rutaAnterior);

        return response()->json(['data' => DB::table('instrumental_quirurgico')->where('id', $id)->first()]);
    }

    public function imagen(string $id): BinaryFileResponse
    {
        $ruta = DB::table('instrumental_quirurgico')->where('id', $id)->value('imagen_ruta');
        abort_unless($ruta && Str::startsWith($ruta, 'imagenes instrumental/') && Storage::disk('instrumental')->exists($ruta), 404);
        return response()->file(Storage::disk('instrumental')->path($ruta), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
