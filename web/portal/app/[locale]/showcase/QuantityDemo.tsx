'use client'

import { useState } from 'react'
import { QuantityStepper } from '../../../components/shop'

/** Client island for the showcase: the stepper is controlled, so it needs state. */
export function QuantityDemo() {
  const [value, setValue] = useState(1)

  return <QuantityStepper value={value} onChange={setValue} max={10} />
}
