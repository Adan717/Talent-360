# Módulo: Expediente Digital y Firmas (Gestor Documental)

El módulo de **Expediente Digital** administra el archivo documental de cada colaborador de la organización, facilitando la auditoría de cumplimiento contractual y de datos de identidad.

---

## 1. Archivos Clave del Módulo
- **Componente Principal**: [GestorDocumentos.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/GestorDocumentos.tsx) (Expedientes individuales de empleados, carga de archivos y previsualización).

---

## 2. Funcionalidades Detalladas

### A. Expedientes Individuales de Colaboradores
- Repositorio digital organizado por empleado.
- Soporte para la carga y almacenamiento de documentos estándar requeridos por ley:
  - Identificación Oficial (INE / Pasaporte).
  - Comprobante de Domicilio.
  - Cédula de Identificación Fiscal (RFC).
  - Clave Única de Registro de Población (CURP).
  - Comprobante de Estudios.

### B. Previsualizador de Archivos Integrado
- Permite a los analistas de Recursos Humanos previsualizar imágenes o PDFs de los expedientes directamente desde el navegador, acelerando la validación administrativa.

### C. Generación de Contratos y Firma Digital
- Creación de contratos de trabajo dinámicos vinculando los datos personales del colaborador (sueldo, puesto, horario).
- **Firma Digital Autógrafa**: El colaborador puede firmar digitalmente su contrato de trabajo dibujando su firma en un panel táctil (Canvas) desde su dispositivo celular o tablet.
- El contrato firmado se bloquea, se genera en formato digital inmutable y se almacena automáticamente en su expediente para futuras consultas.
