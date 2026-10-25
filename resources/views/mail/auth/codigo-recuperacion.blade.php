<x-mail::message>
# Restablece tu contraseÃ±a

Recibimos una solicitud para recuperar el acceso al Sistema ClÃ­nico Hospital MarÃ­a Esperanza.

Usa este cÃ³digo para crear una nueva contraseÃ±a:

# {{ $codigo }}

El cÃ³digo vence en 15 minutos y solo puede utilizarse una vez. Si no solicitaste este cambio, puedes ignorar este correo.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
