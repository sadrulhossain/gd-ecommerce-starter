import { apiCall, readCustomerToken, relay, safely, signedOut } from '../../../../lib/customer-server'

/** The signed-in customer's orders, newest first (`?page=`). */
export async function GET(request: Request) {
  const token = await readCustomerToken()
  if (!token) return signedOut()

  const page = Number.parseInt(new URL(request.url).searchParams.get('page') ?? '1', 10)

  return safely(async () => relay(await apiCall('GET', `/v1/customer/orders?page=${Number.isFinite(page) && page > 0 ? page : 1}`, { bearer: token })))
}
