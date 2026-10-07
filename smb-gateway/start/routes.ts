/*
|--------------------------------------------------------------------------
| Routes file
|--------------------------------------------------------------------------
|
| The routes file is used for defining the HTTP routes.
|
*/

import router from '@adonisjs/core/services/router'
const GatewayHealthController = () => import('#controllers/gateway_health_controller')
const InternalNetworkNotificationController = () => import('#controllers/internal_network_notification_controller')

router.get('/api/v1/health', [GatewayHealthController, 'show']).as('api.v1.health')
router.post('/api/internal/v1/notifications/network-violation', [InternalNetworkNotificationController, 'store'])
