import { createServer } from 'node:http'
import { attachRealtimeGateway, closeRealtimeGateway } from '#services/realtime_gateway'

/*
|--------------------------------------------------------------------------
| HTTP server entrypoint
|--------------------------------------------------------------------------
|
| The "server.ts" file is the entrypoint for starting the AdonisJS HTTP
| server. Either you can run this file directly or use the "serve"
| command to run this file and monitor file changes
|
*/

await import('reflect-metadata')
const { Ignitor, prettyPrintError } = await import('@adonisjs/core/ignitor')

/**
 * URL to the application root. AdonisJS need it to resolve
 * paths to file and directories for scaffolding commands
 */
const APP_ROOT = new URL('../', import.meta.url)

/**
 * The importer is used to import files in context of the
 * application.
 */
const IMPORTER = (filePath: string) => {
  if (filePath.startsWith('./') || filePath.startsWith('../')) {
    return import(new URL(filePath, APP_ROOT).href)
  }
  return import(filePath)
}

const httpProcess = new Ignitor(APP_ROOT, { importer: IMPORTER })
  .tap((adonisApp) => {
    adonisApp.booting(async () => {
      await import('#start/env')
    })
    adonisApp.listen('SIGTERM', () => adonisApp.terminate())
    adonisApp.listen('SIGTERM', () => closeRealtimeGateway())
    adonisApp.listen('SIGINT', () => closeRealtimeGateway())
    adonisApp.listenIf(adonisApp.managedByPm2, 'SIGINT', () => adonisApp.terminate())
  })
  .httpServer()

try {
  await httpProcess.start((handler) => {
    const server = createServer(handler)
    attachRealtimeGateway(server)

    return server
  })
} catch (error) {
  process.exitCode = 1
  prettyPrintError(error)
}
