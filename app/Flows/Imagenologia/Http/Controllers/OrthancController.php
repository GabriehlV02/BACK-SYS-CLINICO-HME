<?php

namespace App\Flows\Imagenologia\Http\Controllers;

use App\Flows\Pacientes\Models\Paciente;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class OrthancController
{
    public function estado(): JsonResponse
    {
        $sistema = $this->orthanc()->get('/system')->throw()->json();

        return response()->json([
            'conectado' => true,
            'nombre' => $sistema['Name'] ?? 'Orthanc',
            'version' => $sistema['Version'] ?? 'Desconocida',
            'servidor' => parse_url((string) config('orthanc.url'), PHP_URL_HOST),
        ]);
    }

    public function estudios(): JsonResponse
    {
        return response()->json($this->obtenerEstudios());
    }

    public function sincronizar(): JsonResponse
    {
        Cache::store('file')->forget('orthanc.estudios');
        $estudios = $this->obtenerEstudios();
        $asignados = collect($estudios)->filter(fn (array $estudio) => $estudio['pacienteId'] !== null)->count();

        return response()->json([
            'total' => count($estudios),
            'nuevos' => 0,
            'asignados' => $asignados,
            'pendientes' => count($estudios) - $asignados,
        ]);
    }

    public function archivo(string $id): Response
    {
        $respuesta = $this->orthanc()->get("/studies/{$id}/archive");
        $respuesta->throw();

        return response($respuesta->body(), 200, [
            'Content-Type' => $respuesta->header('Content-Type', 'application/zip'),
            'Content-Disposition' => 'attachment; filename="estudio-'.$id.'.zip"',
        ]);
    }

    public function actualizar(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'patientName' => ['required', 'string', 'max:200'],
            'patientIdDicom' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'accessionNumber' => ['nullable', 'string', 'max:100'],
            'fechaEstudio' => ['nullable', 'regex:/^\d{8}$/'],
        ]);

        $reemplazos = array_filter([
            'PatientName' => $datos['patientName'],
            'PatientID' => $datos['patientIdDicom'],
            'StudyDescription' => $datos['descripcion'] ?? null,
            'AccessionNumber' => $datos['accessionNumber'] ?? null,
            'StudyDate' => $datos['fechaEstudio'] ?? null,
        ], fn ($valor) => $valor !== null);

        $this->orthanc()->post("/studies/{$id}/modify", [
            'Replace' => $reemplazos,
            'Force' => true,
        ])->throw();

        return response()->json($this->obtenerEstudio($id));
    }

    public function eliminar(string $id): Response
    {
        $this->orthanc()->delete("/studies/{$id}")->throw();

        return response()->noContent();
    }

    private function obtenerEstudios(): array
    {
        return Cache::store('file')->remember('orthanc.estudios', now()->addSeconds(60), function (): array {
            $ids = $this->orthanc()->get('/studies')->throw()->json();
            $pacientes = Paciente::query()->get(['id', 'ci', 'nombres', 'apellido_paterno', 'apellido_materno']);

            return collect($ids)
                ->map(fn (string $id) => $this->obtenerEstudio($id, $pacientes))
                ->sortByDesc('fechaEstudio')
                ->values()
                ->all();
        });
    }

    private function obtenerEstudio(string $id, $pacientes = null): array
    {
        $estudio = $this->orthanc()->get("/studies/{$id}")->throw()->json();
        $tagsEstudio = $estudio['MainDicomTags'] ?? [];
        $tagsPaciente = $estudio['PatientMainDicomTags'] ?? [];
        $nombre = trim((string) ($tagsPaciente['PatientName'] ?? ''));
        $documento = trim((string) ($tagsPaciente['PatientID'] ?? ''));
        $pacientes ??= Paciente::query()->get(['id', 'ci', 'nombres', 'apellido_paterno', 'apellido_materno']);
        $paciente = $this->buscarPaciente($pacientes, $documento, $nombre);

        return [
            'id' => $id,
            'studyInstanceUid' => $estudio['MainDicomTags']['StudyInstanceUID'] ?? '',
            'patientIdDicom' => $documento,
            'patientName' => $nombre,
            'pacienteId' => $paciente?->id,
            'accessionNumber' => $tagsEstudio['AccessionNumber'] ?? '',
            'descripcion' => $tagsEstudio['StudyDescription'] ?? '',
            'fechaEstudio' => $tagsEstudio['StudyDate'] ?? '',
            'modalidades' => array_values(array_unique(array_filter(array_map(
                fn (string $serieId) => $this->orthanc()
                    ->get("/series/{$serieId}")
                    ->throw()
                    ->json('MainDicomTags.Modality'),
                $estudio['Series'] ?? [],
            )))),
            'cantidadSeries' => count($estudio['Series'] ?? []),
            'actualizadoEn' => $estudio['LastUpdate'] ?? '',
        ];
    }

    private function buscarPaciente($pacientes, string $documento, string $nombre): ?Paciente
    {
        $normalizar = fn (string $valor) => mb_strtoupper(preg_replace('/\s+/', ' ', trim($valor)) ?: '');
        $documento = $normalizar($documento);
        $nombre = $normalizar($nombre);

        return $pacientes->first(function (Paciente $paciente) use ($normalizar, $documento, $nombre): bool {
            if ($documento !== '' && $normalizar($paciente->ci) === $documento) {
                return true;
            }

            return $nombre !== '' && $normalizar(implode(' ', [
                $paciente->nombres,
                $paciente->apellido_paterno,
                $paciente->apellido_materno,
            ])) === $nombre;
        });
    }

    private function orthanc(): PendingRequest
    {
        $cliente = Http::baseUrl((string) config('orthanc.url'))
            ->acceptJson()
            ->timeout((int) config('orthanc.timeout'))
            ->retry(1, 250, throw: false);

        $usuario = config('orthanc.username');

        if ($usuario !== null && $usuario !== '') {
            $cliente = $cliente->withBasicAuth((string) $usuario, (string) config('orthanc.password'));
        }

        return $cliente;
    }
}
