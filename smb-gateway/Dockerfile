FROM node:24-alpine AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build && npm prune --omit=dev
RUN chown -R node:node /app

FROM node:24-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app/build
COPY --from=build --chown=node:node /app/build ./
COPY --from=build --chown=node:node /app/node_modules /app/node_modules
USER node
EXPOSE 3333
CMD ["node", "bin/server.js"]
