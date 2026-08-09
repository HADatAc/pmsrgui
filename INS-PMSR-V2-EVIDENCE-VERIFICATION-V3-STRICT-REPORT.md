# Evidence Verification V3 (Strict)

This report applies stricter acceptance criteria to deep-scan results.

## Strict Rule
- A component is explicit only when snippet contains:
  1) exact component phrase
  2) component kind term (detector/actuator/etc.)
  3) at least one sensor/component signal term

## Verdict Counts
- NO_COMPONENT_SIGNAL_FOUND: 63
- PARTIAL_MATCH_NEEDS_HUMAN_CONFIRMATION: 103

- Demoted weak explicit candidates: 4

## Demoted Examples
- Chison Cbit-9 Stationary Ultrasound :: Chison Cbit-9 Stationary Ultrasound Actuator :: https://www.chison.com/products/
- Chison Cbit-9 Stationary Ultrasound :: Chison Cbit-9 Stationary Ultrasound Detector :: https://www.chison.com/products/
- Vascular Access Ultrasound Simulator :: Vascular Access Ultrasound Simulator Actuator :: https://www.chison.com/contacts/service-support/
- Vascular Access Ultrasound Simulator :: Vascular Access Ultrasound Simulator Detector :: https://www.chison.com/contacts/service-support/