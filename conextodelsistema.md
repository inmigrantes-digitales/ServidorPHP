# Acceso Senior — Documentación General del Proyecto

## 1. Introducción

Acceso Senior es una aplicación digital diseñada para reducir la brecha tecnológica que afecta a personas mayores con bajo nivel de alfabetización digital. El sistema permite registrar consultas relacionadas con trámites digitales y facilitar la asistencia mediante una red de facilitadores digitales y centros comunitarios.

El proyecto se desarrolla como un MVP (Producto Mínimo Viable) que permita validar el funcionamiento del sistema en un entorno real, priorizando las funcionalidades esenciales.

## 2. Objetivos del Sistema

### Objetivo general

Proporcionar una plataforma accesible que permita a personas mayores registrar problemas relacionados con trámites digitales y recibir asistencia de facilitadores.

### Objetivos específicos

* Facilitar el registro de consultas por parte de personas mayores.
* Permitir a facilitadores gestionar y resolver casos.
* Registrar el historial de acciones realizadas sobre cada consulta.
* Proveer a los administradores herramientas de gestión y seguimiento.

## 3. Tipos de Usuarios

### Persona mayor (Consultante)

Puede registrar consultas o problemas vinculados a trámites digitales.

Funciones principales:

* Registrar una consulta.
* Consultar el estado del caso.
* Recibir información sobre el avance del caso.

### Facilitador digital

Voluntario que ayuda a resolver las consultas.

Funciones principales:

* Visualizar casos disponibles.
* Tomar casos asignados.
* Gestionar el avance del caso.
* Marcar casos como resueltos.

### Facilitador de centro comunitario

Usuario que opera desde un centro presencial.

Funciones principales:

* Registrar consultas en nombre de personas mayores.
* Consultar casos del centro.

### Administrador

Responsable de la gestión general del sistema.

Funciones principales:

* Crear y administrar usuarios.
* Supervisar casos activos.
* Generar reportes.

## 4. Funcionalidades del Sistema

### Registro de consultas

Las personas mayores pueden registrar problemas relacionados con trámites digitales.

Datos registrados:

* Nombre (opcional)
* Teléfono
* Tipo de problema
* Descripción
* Método de ingreso (voz, texto o centro)

### Seguimiento del caso

El sistema permite consultar el estado de cada caso.

Estados posibles:

* Caso creado
* Caso asignado
* Caso en proceso
* Caso resuelto
* Caso cerrado

### Gestión de casos

Los facilitadores pueden:

* Tomar casos disponibles
* Actualizar su estado
* Registrar acciones realizadas

### Historial de acciones

Cada modificación sobre un caso queda registrada en el historial para asegurar trazabilidad.

## 5. Arquitectura del Sistema

El sistema sigue una arquitectura basada en API REST.

Componentes principales:

Frontend
Aplicación móvil o web utilizada por los usuarios.

Backend
API desarrollada en PHP que gestiona la lógica del sistema.

Base de datos
Base de datos MySQL que almacena la información de usuarios, centros y casos.

## 6. Infraestructura

El sistema se implementa utilizando un servicio de Web Hosting.

Componentes de infraestructura:

Servidor web
Apache ejecutando la API en PHP.

Base de datos
MySQL administrada mediante phpMyAdmin.

Hosting
Web Hosting Plan 1 de DonWeb.

## 7. Modelo de Datos

El sistema utiliza las siguientes tablas principales:

centers
Centros comunitarios donde se atienden consultas.

users
Usuarios del sistema (consultantes, facilitadores, administradores).

problem_types
Categorías de problemas que pueden registrar los usuarios.

cases
Consultas registradas en el sistema.

case_history
Registro de acciones realizadas sobre cada caso.

## 8. Flujo de funcionamiento

1. Una persona mayor registra una consulta.
2. El sistema crea un caso en la base de datos.
3. Un facilitador visualiza el caso disponible.
4. El facilitador toma el caso.
5. El caso pasa a estado "en proceso".
6. Una vez resuelto, el facilitador lo marca como resuelto.
7. Finalmente el caso se cierra.

## 9. Alcance del MVP

La versión inicial del sistema incluye:

* Registro de consultas
* Gestión básica de casos
* Panel para facilitadores
* Administración básica

Funcionalidades futuras:

* Geolocalización de casos
* Chat entre consultante y facilitador
* Clasificación automática de casos mediante IA
* Notificaciones por WhatsApp

## 10. Consideraciones de accesibilidad

El sistema está diseñado considerando las necesidades de personas mayores:

* Interfaz simple
* Navegación con pocos pasos
* Botones grandes
* Posibilidad de ingreso por voz

## 11. Escalabilidad

La arquitectura del sistema permite ampliar funcionalidades en el futuro, incorporar nuevos centros comunitarios y aumentar la cantidad de facilitadores sin modificar la estructura principal del sistema.

---

Documentación general del proyecto Acceso Senior.
