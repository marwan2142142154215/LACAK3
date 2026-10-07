FROM node:24-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
ARG VITE_API_URL=https://api.lacaksmbbot.com
ARG VITE_SENTRY_DSN=
ENV VITE_API_URL=${VITE_API_URL}
ENV VITE_SENTRY_DSN=${VITE_SENTRY_DSN}
RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=20s --timeout=3s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1/healthz || exit 1
