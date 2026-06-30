# Etapa 1: Build
FROM node:20-alpine as build

WORKDIR /app

# Copiar configuración de dependencias
COPY package.json package-lock.json ./

# Instalar dependencias
RUN npm ci

# Copiar código fuente
COPY . .

# Construir aplicación para producción
RUN npm run build

# Etapa 2: Producción (Nginx)
FROM nginx:alpine

# Copiar configuración custom de Nginx si es necesario (ej. para React Router)
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copiar los estáticos compilados desde la etapa 1
COPY --from=build /app/dist /usr/share/nginx/html

# Exponer el puerto de Nginx
EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
