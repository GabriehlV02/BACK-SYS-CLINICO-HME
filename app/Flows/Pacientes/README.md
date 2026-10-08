# Flujo: Pacientes

## Registro

`POST /api/v1/pacientes` crea un paciente en la base clínica. Cada acción del
flujo tiene su propio archivo: validación (`Requests`), controlador HTTP
(`Http/Controllers`), regla de negocio (`Services`) y persistencia (`Models`).

Campos obligatorios: `nombres`, `apellido_paterno`, `apellido_materno`, `ci`,
`fecha_nacimiento`, `genero`, `pais` y `departamento`.

## Integración contable pendiente

No se duplican pacientes en esta base. Cuando se implemente Contable, creará
un `cliente` usando el UUID de `pacientes.id` como `paciente_id` lógico. Los
datos que se podrán enviar son: nombre completo/razón social, CI o NIT,
teléfono y domicilio. La sincronización no tendrá una llave foránea entre
bases; será mediante API entre los backends.
