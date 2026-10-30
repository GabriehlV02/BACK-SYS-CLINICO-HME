<x-mail::message>
# Restablece tu contraseña

Recibimos una solicitud para recuperar el acceso al Sistema Clínico Hospital María Esperanza.

Usa este código para crear una nueva contraseña:

# {{ $codigo }}

El código vence en 15 minutos y solo puede utilizarse una vez. Si no solicitaste este cambio, puedes ignorar este correo.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
