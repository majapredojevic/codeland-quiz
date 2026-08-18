FROM node:24.19.0-alpine3.23 AS frontend-build

WORKDIR /application/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts

COPY frontend/ ./
RUN npm run build -- --configuration production

FROM nginx:1.30.4-alpine3.24

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=frontend-build /application/frontend/dist/frontend/browser/ /usr/share/nginx/html/
