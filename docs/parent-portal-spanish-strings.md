# Teams Elevated — Parent Portal: Spanish (Latin American) Translation

**Counts:** 408 translated / 59 excluded / 467 total strings.

**Register:** neutral Latin American Spanish for US Hispanic families. *Usted* for instructions and
help text; short, natural imperatives for buttons.

**Product noun choices (kept consistent throughout):**

| English | Español | Notes |
|---|---|---|
| Crew | **Familia** | Chosen term. Alternative if the club prefers something more explicitly "support network": **Equipo de apoyo**. (No literal "Crew" string appears in this extract — the choice is recorded here so it is fixed before the term reaches the UI.) |
| athlete | atleta | |
| club | club | |
| team | equipo | |
| coach | entrenador / entrenadora (written *entrenador/a* where gender is unknown) | |
| RSVP | confirmar asistencia | verb form; the noun state is "asistencia" |
| jersey size | talla de camiseta | feminine — sizes agree: *chica / mediana / grande* |
| consent | consentimiento | |
| invoice | factura | |
| parent portal | portal de familias | |

**Ambiguous strings:** 16, each marked ⚠️ in the Note column with the reading I assumed.

Conventions: `{placeholders}` and `${template.expressions}` are preserved verbatim; numbers and
ALL-CAPS styling are preserved; button labels are kept within +30% of the English length.

---

## Parent portal — pages

### frontend/src/parent-portal/ParentDashboard.tsx

| English | Español | Note |
|---|---|---|
| Make Payment | Hacer un pago | |
| View Schedule | Ver calendario | |
| My Athletes | Mis atletas | |
| Upcoming Events | Próximos eventos | |
| Announcements | Anuncios | |
| Here's what's happening with your athletes. | Esto es lo que está pasando con sus atletas. | |
| View All | Ver todo | |
| Full Schedule | Calendario completo | |

### frontend/src/parent-portal/pages/AnnouncementsPage.tsx

| English | Español | Note |
|---|---|---|
| Announcements | Anuncios | |
| No Announcements | Sin anuncios | |
| There are no announcements at this time. | No hay anuncios en este momento. | |
| Failed to load announcements | No se pudieron cargar los anuncios | Rendered as an error state, not console-only |
| Yesterday | Ayer | |
| Urgent | Urgente | Priority badge |
| Important | Importante | Priority badge |

### frontend/src/parent-portal/pages/AthleteDetailPage.tsx

| English | Español | Note |
|---|---|---|
| Athlete Details | Detalles del atleta | |
| Access denied. You do not have permission to view this athlete. | Acceso denegado. No tiene permiso para ver a este atleta. | |
| Payments | Pagos | |
| Medical | Salud | ⚠️ Tab label. Assumed the short tab for the medical section; "Salud" reads better than "Médico" as a standalone tab |
| Documents | Documentos | |
| Schedule | Calendario | |
| Upcoming Schedule | Próximas actividades | |
| See all | Ver todo | |
| No upcoming events. | No hay eventos próximos. | |
| Registrations | Inscripciones | |
| No registrations yet. | Aún no hay inscripciones. | |
| Coaches | Entrenadores | |
| Contact Information | Información de contacto | |
| Address | Dirección | |
| Data Privacy | Privacidad de datos | |
| Under our privacy policy, you have the right to request deletion of your child's personal and medical data. | Según nuestra política de privacidad, usted tiene derecho a solicitar que se eliminen los datos personales y médicos de su hijo/a. | |
| Delete My Child's Data | Eliminar los datos de mi hijo/a | |
| This action | Esta acción | ⚠️ Sentence fragment — assumed it joins "cannot be undone" around a bold span |
| cannot be undone | no se puede deshacer | ⚠️ Second half of the fragment above |
| Type | Escriba | ⚠️ Fragment of "Type <word> to confirm"; imperative assumed, not the noun "type/kind" |
| to confirm | para confirmar | ⚠️ Second half of the fragment above |
| Cancel | Cancelar | |
| Athlete not found | No se encontró al atleta | |
| Failed to load athlete details | No se pudieron cargar los detalles del atleta | |
| Failed to delete data. Please try again. | No se pudieron eliminar los datos. Inténtelo de nuevo. | |
| Unable to reach the server. Please try again later. | No se pudo conectar con el servidor. Inténtelo de nuevo más tarde. | |
| Head Coach | Entrenador/a principal | |
| Assistant Coach | Entrenador/a asistente | |
| Team Manager | Coordinador/a del equipo | ⚠️ Youth-sports role. "Gerente" sounds corporate; "Coordinador/a" (or regionally "Delegado/a") is what families say |
| Deleting... | Eliminando... | |
| Delete Data | Eliminar datos | |

### frontend/src/parent-portal/pages/DocumentsPage.tsx

| English | Español | Note |
|---|---|---|
| Please select an athlete to view documents. | Seleccione un atleta para ver los documentos. | |
| Required | Obligatorio | |
| Failed to load documents | No se pudieron cargar los documentos | |
| Valid | Vigente | Document status |
| Expiring Soon | Por vencer | |
| Expired | Vencido | |
| No Documents | Sin documentos | |
| No documents have been uploaded yet. | Todavía no se ha subido ningún documento. | |
| No documents match this filter. | Ningún documento coincide con este filtro. | |
| Expires | Vence | |
| No ${filter.replace('_', ' ')} Documents | No hay documentos con el estado ${filter.replace('_', ' ')} | ⚠️ The interpolated value is an English filter key ("expiring_soon"), so it will stay English inside a Spanish sentence. Recommend replacing this composed string with one per filter value |

### frontend/src/parent-portal/pages/MakePaymentPage.tsx

| English | Español | Note |
|---|---|---|
| Make Payment | Hacer un pago | |
| All Paid Up! | ¡Todo pagado! | |
| You have no outstanding payments. | No tiene pagos pendientes. | |
| Select Invoices | Seleccione las facturas | |
| Payment Amount | Monto del pago | |
| Pay Full Amount | Pagar el total | |
| Pay Custom Amount | Pagar otro monto | |
| Payment Method | Método de pago | |
| Default | Predeterminado | ⚠️ Assumed "default payment method" badge, not a verb |
| Total | Total | |
| Invoice | Factura | |
| Failed to load payment information | No se pudo cargar la información de pago | |
| Please select at least one invoice | Seleccione al menos una factura | |
| Please enter a valid payment amount | Ingrese un monto de pago válido | |
| Could not start payment | No se pudo iniciar el pago | |
| Payment processing failed. Please try again. | No se pudo procesar el pago. Inténtelo de nuevo. | |
| Processing... | Procesando... | |
| Pay Now | Pagar ahora | |

### frontend/src/parent-portal/pages/MedicalInfoPage.tsx

| English | Español | Note |
|---|---|---|
| Medical Info | Información médica | |
| Medical | Salud | Same tab label as on the athlete detail page |
| Allergies | Alergias | |
| e.g. peanuts, bee stings | p. ej. cacahuates, picaduras de abeja | Placeholder text |
| Allergy severity | Gravedad de la alergia | |
| e.g. severe / anaphylactic | p. ej. grave / anafiláctica | |
| Medical conditions | Condiciones médicas | |
| Current medications | Medicamentos actuales | |
| Blood type | Tipo de sangre | |
| Asthma & Allergy Devices | Dispositivos para asma y alergias | |
| Inhaler location | Dónde está el inhalador | |
| e.g. in their kit bag | p. ej. en su maleta deportiva | |
| Carries an EpiPen | Lleva un EpiPen | Brand name kept |
| EpiPen location | Dónde está el EpiPen | |
| e.g. side pocket of backpack | p. ej. en el bolsillo lateral de la mochila | |
| Physician & Insurance | Médico y seguro | |
| Physician name | Nombre del médico | |
| Physician phone | Teléfono del médico | |
| Insurance provider | Compañía de seguro | |
| Policy number | Número de póliza | |
| Group number | Número de grupo | Insurance group number |
| Physical | Examen físico | ⚠️ Read as the sports physical exam, not the adjective |
| Last physical | Último examen físico | |
| Physical expires | El examen físico vence | |
| Anything else the club should know | Cualquier otra cosa que el club deba saber | |
| Physician | Médico | |
| Notes for the club | Notas para el club | |
| Clinical (club-managed) | Clínico (administrado por el club) | Read-only section |
| Concussion history | Historial de conmociones cerebrales | |
| Last concussion | Última conmoción cerebral | |
| Cleared to return | Autorizado para regresar | Return-to-play clearance |
| Access denied. You do not have permission to view this athlete's medical information. | Acceso denegado. No tiene permiso para ver la información médica de este atleta. | |
| Cancel | Cancelar | |
| Saved. | Guardado. | |
| Recorded by club staff. Contact your club to correct anything here. | Registrado por el personal del club. Comuníquese con su club para corregir cualquier dato aquí. | |
| Failed to load medical information | No se pudo cargar la información médica | |
| Could not save. Please try again. | No se pudo guardar. Inténtelo de nuevo. | |
| Unable to reach the server. Please try again. | No se pudo conectar con el servidor. Inténtelo de nuevo. | |
| Not provided | No proporcionado | |
| Medical information not found | No se encontró la información médica | |
| Saving... | Guardando... | |
| Save | Guardar | |
| On file with the club. | Registrado con el club. | |
| Nothing on file yet. | Aún no hay nada registrado. | |
| Edit | Editar | |
| Add details | Agregar detalles | |
| Yes | Sí | |

### frontend/src/parent-portal/pages/MoreMenuPage.tsx

| English | Español | Note |
|---|---|---|
| More | Más | |
| Install App | Instalar la app | |
| Install | Instalar | |
| Staff view | Vista del personal | Link to the staff app for coach-parents |
| Teams, schedules and club tools | Equipos, calendarios y herramientas del club | |
| Report an issue | Reportar un problema | |
| Something not working? Tell us | ¿Algo no funciona? Avísenos | |
| Log Out | Cerrar sesión | |
| My Athletes | Mis atletas | |
| View and manage your athletes | Vea y administre a sus atletas | |
| Announcements | Anuncios | |
| Team and club announcements | Anuncios del equipo y del club | |
| Documents | Documentos | |
| View and download documents | Vea y descargue documentos | |
| Volunteer | Voluntariado | |
| View assignments and sign up to volunteer | Vea sus asignaciones e inscríbase como voluntario/a | |
| Account Settings | Configuración de la cuenta | |
| Update your profile and preferences | Actualice su perfil y preferencias | |
| Add to home screen for quick access | Agréguelo a la pantalla de inicio para acceder rápido | |
| Install for a better experience | Instálelo para una mejor experiencia | |
| Add to Home Screen | Agregar a la pantalla de inicio | |

### frontend/src/parent-portal/pages/MyAthletesPage.tsx

| English | Español | Note |
|---|---|---|
| My Athletes | Mis atletas | |

### frontend/src/parent-portal/pages/PaymentStatusPage.tsx

| English | Español | Note |
|---|---|---|
| Payments | Pagos | |
| Payment received — thank you! Balances update automatically within a few seconds. | Pago recibido, ¡gracias! Los saldos se actualizan automáticamente en unos segundos. | |
| Checkout was cancelled — no payment was made. | Se canceló el pago; no se realizó ningún cobro. | |
| Total Outstanding | Total pendiente | |
| Description | Descripción | |
| Amount | Monto | |
| Total | Total | |
| Paid | Pagado | |
| Payment history | Historial de pagos | |
| Link copied — paste it into a text or email! | Enlace copiado. ¡Péguelo en un mensaje de texto o correo electrónico! | |
| Pay Now | Pagar ahora | |
| Invoice | Factura | |
| Failed to load invoices | No se pudieron cargar las facturas | |
| Failed to load payment information | No se pudo cargar la información de pago | |
| Could not create the link | No se pudo crear el enlace | |
| Failed to load invoice details | No se pudieron cargar los detalles de la factura | |
| No Outstanding Payments | Sin pagos pendientes | |
| No Payment History | Sin historial de pagos | |
| No Invoices Found | No se encontraron facturas | |
| Invoices will appear here when available. | Las facturas aparecerán aquí cuando estén disponibles. | |
| Overdue: | Vencida: | Agrees with "factura" (feminine) |
| Due: | Vence: | |
| Hide details | Ocultar detalles | |
| View details | Ver detalles | |
| Creating link… | Creando enlace… | |
| Share with family & friends | Compartir con familiares y amigos | Contribution link — for relatives to help pay |

### frontend/src/parent-portal/pages/ScheduleRSVPPage.tsx

| English | Español | Note |
|---|---|---|
| Event Details | Detalles del evento | |
| Details | Detalles | |
| Notes | Notas | |
| Event not found | No se encontró el evento | |
| Failed to load event details | No se pudieron cargar los detalles del evento | |
| RSVP updated successfully | Se actualizó su confirmación de asistencia | |
| Failed to update RSVP | No se pudo actualizar su confirmación de asistencia | |
| Going | Asistirá | Parent answers on the athlete's behalf, hence third person |
| Not Going | No asistirá | |
| Maybe | Tal vez | |
| Saving... | Guardando... | |
| Save RSVP | Confirmar asistencia | |

### frontend/src/parent-portal/pages/TeamChatPage.tsx

| English | Español | Note |
|---|---|---|
| Messages | Mensajes | |
| Type a message... | Escriba un mensaje... | |
| New message | Mensaje nuevo | |
| No Messages | Sin mensajes | |
| No Messages Yet | Aún no hay mensajes | |
| Be the first to send a message! | ¡Sea el primero en enviar un mensaje! | |
| Message removed by an administrator | Mensaje eliminado por un administrador | |
| Connecting to chat... | Conectando al chat... | |
| Coach | Entrenador/a | Role badge next to a sender's name |
| Admin | Administrador/a | Role badge |
| Not delivered | No se entregó | |
| Sending… | Enviando… | |

### frontend/src/parent-portal/pages/UpcomingEventsPage.tsx

| English | Español | Note |
|---|---|---|
| Schedule | Calendario | |
| Previous month | Mes anterior | |
| Next month | Mes siguiente | |
| List View | Vista de lista | |
| Calendar View | Vista de calendario | |
| No Upcoming Events | Sin eventos próximos | |
| There are no scheduled events at this time. | No hay eventos programados en este momento. | |
| Today | Hoy | |
| Attending | Asistirá | Legend for the RSVP dot |
| Maybe | Tal vez | |
| Not attending | No asistirá | |
| No dot = not yet responded | Sin punto = aún sin responder | |
| Failed to load events | No se pudieron cargar los eventos | |
| Tomorrow | Mañana | |
| Going | Asistirá | |
| Not Going | No asistirá | |
| Sun | Dom | Calendar column header |
| Mon | Lun | |
| Tue | Mar | |
| Wed | Mié | |
| Thu | Jue | |
| Fri | Vie | |

### frontend/src/parent-portal/pages/VolunteerPage.tsx

| English | Español | Note |
|---|---|---|
| Volunteer | Voluntariado | Page title |
| Any relevant experience, availability, etc. | Experiencia relevante, disponibilidad, etc. | Textarea placeholder |
| Cleared | Aprobada | ⚠️ Background-check status; agrees with "verificación" (feminine) |
| Pending | Pendiente | |
| Expired | Vencido | |
| Not Submitted | Sin enviar | |
| Active | Activo | |
| Inactive | Inactivo | |
| Your background check is not yet cleared. You can submit signup requests, but they cannot be approved until your background check is cleared. | Su verificación de antecedentes aún no está aprobada. Puede enviar solicitudes de inscripción, pero no se podrán aprobar hasta que su verificación de antecedentes esté aprobada. | |
| My Volunteer Assignments | Mis asignaciones de voluntariado | |
| You're not currently volunteering for any teams | Actualmente no es voluntario/a en ningún equipo | |
| BG Check: | Antecedentes: | English abbreviates; Spanish spells it out to stay clear |
| Status: | Estado: | |
| Available Opportunities | Oportunidades disponibles | |
| No teams are currently looking for volunteers. | Por ahora ningún equipo está buscando voluntarios. | |
| Already volunteering | Ya es voluntario/a | |
| Request pending | Solicitud pendiente | |
| Sign Up | Inscribirse | |
| Volunteer Sign Up | Inscripción de voluntarios | Dialog title |
| Notes (optional) | Notas (opcional) | |
| Cancel | Cancelar | |
| Failed to load volunteer assignments | No se pudieron cargar las asignaciones de voluntariado | |
| Failed to load available teams | No se pudieron cargar los equipos disponibles | |
| Your request has been submitted for review | Su solicitud se envió para revisión | |
| Signup failed. Please try again. | No se pudo completar la inscripción. Inténtelo de nuevo. | |
| Confirm | Confirmar | |

---

## Parent portal — components and hooks

### frontend/src/parent-portal/components/AthleteSelector.tsx

| English | Español | Note |
|---|---|---|
| All Athletes | Todos los atletas | |

### frontend/src/parent-portal/components/BottomNavigation.tsx

| English | Español | Note |
|---|---|---|
| Home | Inicio | |
| Schedule | Calendario | |
| Payments | Pagos | |
| More | Más | |

### frontend/src/parent-portal/components/ConsentGate.tsx

| English | Español | Note |
|---|---|---|
| We need a parent's consent first | Primero necesitamos el consentimiento de un padre o una madre | |
| Without it your club can't keep your child's information in this system, so there's nothing for the portal to show you. | Sin él, su club no puede conservar la información de su hijo/a en este sistema, así que el portal no tiene nada que mostrarle. | |
| If you have questions about what's collected or why, contact your club directly — they can talk it through, and you can come back and agree at any time. | Si tiene preguntas sobre qué información se recopila o por qué, comuníquese directamente con su club: ellos pueden explicárselo, y usted puede volver y aceptar en cualquier momento. | |
| Back | Atrás | |
| Sign out | Cerrar sesión | |
| Before you use the portal, your club needs your consent as the parent or legal guardian. This is asked once per child. | Antes de usar el portal, su club necesita su consentimiento como padre, madre o tutor legal. Esto se solicita una vez por cada hijo/a. | |
| Privacy Policy | Política de privacidad | |
| I consent to the collection and encrypted storage of my child's medical information, accessible only to authorized staff for safety purposes — for example allergies, medications and medical conditions. | Doy mi consentimiento para la recopilación y el almacenamiento cifrado de la información médica de mi hijo/a, a la que solo tendrá acceso el personal autorizado por motivos de seguridad; por ejemplo, alergias, medicamentos y condiciones médicas. | Legal wording — have club counsel review before shipping; the Spanish text is the record of what the family agreed to |
| I don't agree | No acepto | |
| We'll email you a confirmation link for your records. Your consent is recorded now — you can withdraw it at any time from the portal. | Le enviaremos por correo electrónico un enlace de confirmación para sus registros. Su consentimiento queda registrado ahora y puede retirarlo en cualquier momento desde el portal. | |
| Could not record consent. | No se pudo registrar el consentimiento. | |
| Could not record consent. Please try again. | No se pudo registrar el consentimiento. Inténtelo de nuevo. | |
| Confirm your consent | Confirme su consentimiento | |
| Parental consent | Consentimiento de los padres | |
| This applies to | Esto aplica a | Followed by the children's names |
| Recording... | Registrando... | |
| I agree | Acepto | |

### frontend/src/parent-portal/components/DashboardCard.tsx

| English | Español | Note |
|---|---|---|
| Just now | Ahora mismo | |

### frontend/src/parent-portal/components/InstallPrompt.tsx

| English | Español | Note |
|---|---|---|
| Dismiss | Descartar | |
| Install Teams Elevated | Instalar Teams Elevated | Product name kept |
| Tap the share button | Toque el botón de compartir | iOS instructions |
| Install Teams Elevated for quick access | Instale Teams Elevated para tenerlo a la mano | |
| Install | Instalar | |
| Tap the menu | Toque el menú | Android instructions |
| Add to Home Screen | Agregar a la pantalla de inicio | |
| Install App | Instalar la app | |

### frontend/src/parent-portal/components/JerseySizeCard.tsx

| English | Español | Note |
|---|---|---|
| Uniform | Uniforme | ⚠️ Card heading above the jersey size; read as the noun, not "uniform/consistent" |
| Jersey size | Talla de camiseta | |
| Saved. | Guardado. | |
| Not sure yet | Aún no estoy seguro/a | Option for a family who has not measured yet |
| Cancel | Cancelar | |
| Could not save the size. Please try again. | No se pudo guardar la talla. Inténtelo de nuevo. | |
| Unable to reach the server. Please try again. | No se pudo conectar con el servidor. Inténtelo de nuevo. | |
| Edit | Editar | |
| Add size | Agregar talla | |
| Not set | Sin definir | |
| Saving... | Guardando... | |
| Save | Guardar | |

### frontend/src/parent-portal/components/NoAthletesLinked.tsx

| English | Español | Note |
|---|---|---|
| No athletes connected yet | Aún no hay atletas conectados | |
| If your club has a different email address for you, that is why this is empty. | Si su club tiene registrado otro correo electrónico para usted, esa es la razón por la que esto aparece vacío. | |

### frontend/src/parent-portal/components/ParentErrorBoundary.tsx

| English | Español | Note |
|---|---|---|
| Something went wrong | Algo salió mal | |
| We couldn't load this page. Please try again. | No pudimos cargar esta página. Inténtelo de nuevo. | |
| Back to Home | Volver al inicio | |

### frontend/src/parent-portal/components/ParentHeader.tsx

| English | Español | Note |
|---|---|---|
| Go back | Volver | aria-label on the back arrow |

### frontend/src/parent-portal/components/SponsorMarquee.tsx

| English | Español | Note |
|---|---|---|
| Our Sponsors | Nuestros patrocinadores | |

### frontend/src/parent-portal/hooks/useParentAthletes.ts

| English | Español | Note |
|---|---|---|
| Not authenticated | No ha iniciado sesión | ⚠️ Thrown as an Error; it reaches the family only if the caller renders `error`. Translate, but consider replacing with a friendlier sentence |
| Failed to load athletes | No se pudieron cargar los atletas | Surfaces in the dashboard error state |

---

## Shared components and utilities

### SHARED frontend/src/components/BrandingLogo.tsx

| English | Español | Note |
|---|---|---|
| Loading... | Cargando... | |
| Organization logo | Logotipo de la organización | Image alt text |

### SHARED frontend/src/components/chat/ConversationList.tsx

| English | Español | Note |
|---|---|---|
| Back to chats | Volver a los chats | |
| Archived | Archivados | |
| New Message | Mensaje nuevo | |
| Yesterday | Ayer | |
| No archived chats. | No hay chats archivados. | |
| No conversations yet. | Aún no hay conversaciones. | |
| Restore to your chats | Restaurar a sus chats | |
| Hide from your chats. Nothing is deleted, and a new message brings it back. | Ocultar de sus chats. No se elimina nada, y un mensaje nuevo lo vuelve a mostrar. | |
| Restore ${conv.displayName} to your chats | Restaurar ${conv.displayName} a sus chats | aria-label |
| Archive ${conv.displayName} | Archivar ${conv.displayName} | aria-label |

### SHARED frontend/src/components/chat/MessageReactions.tsx

| English | Español | Note |
|---|---|---|
| Add a reaction | Agregar una reacción | |
| React with ${emoji} | Reaccionar con ${emoji} | aria-label |

### SHARED frontend/src/components/chat/NewConversationDialog.tsx

| English | Español | Note |
|---|---|---|
| Close | Cerrar | |
| Search people or teams… | Buscar personas o equipos… | |
| New Message | Mensaje nuevo | |
| Browse Teams | Ver equipos | |
| No teams available. | No hay equipos disponibles. | |
| Everyone in role | Todos los que tienen este rol | ⚠️ Fragment — assumed the header over role-group results ("everyone holding this role"), not an instruction |
| Teams | Equipos | Section heading |
| People | Personas | Section heading |
| Other | Otros | Section heading for ungrouped results |
| No matches. | Sin resultados. | |
| Start typing to search people, or browse teams. | Escriba para buscar personas o consulte los equipos. | |
| Remove ${p.display_name} | Quitar a ${p.display_name} | Chip removal aria-label |
| Remove ${t.name} | Quitar ${t.name} | |
| Remove ${r.label} | Quitar ${r.label} | |

### SHARED frontend/src/components/chat/PinnedBanner.tsx

| English | Español | Note |
|---|---|---|
| Unpin | Dejar de fijar | |

### SHARED frontend/src/components/chat/PollMessage.tsx

| English | Español | Note |
|---|---|---|
| Vote to see results | Vote para ver los resultados | |

### SHARED frontend/src/components/chat/ReportMessageButton.tsx

| English | Español | Note |
|---|---|---|
| You reported this message | Usted reportó este mensaje | |
| Report this message | Reportar este mensaje | |
| Reported | Reportado | |
| A club administrator will review it. | Un administrador del club lo revisará. | |
| Safety concern | Problema de seguridad | Report reason |
| Harassment or bullying | Acoso o intimidación | |
| Inappropriate content | Contenido inapropiado | |
| Shares personal information | Comparte información personal | |
| Spam | Spam | Kept — "spam" is the common term in Spanish |
| Something else | Otro motivo | |

### SHARED frontend/src/components/evaluations/AthleteEvaluationsPanel.tsx

| English | Español | Note |
|---|---|---|
| Loading evaluations... | Cargando evaluaciones... | |
| Mid-year evaluation | Evaluación de mitad de año | |
| New evaluation | Nueva evaluación | |
| Evaluations are not switched on for this club yet. | Las evaluaciones aún no están activadas para este club. | |
| Overall | General | ⚠️ Read as the overall score label, not "in general" |
| Notes | Notas | |
| Development plan | Plan de desarrollo | |
| Edit | Editar | |
| Delete | Eliminar | |
| Could not load evaluations. | No se pudieron cargar las evaluaciones. | |
| Unable to reach the server. | No se pudo conectar con el servidor. | |
| Could not delete the evaluation. | No se pudo eliminar la evaluación. | |
| Season check-ins and individual development plans, scored on the club’s criteria. | Revisiones de temporada y planes de desarrollo individuales, calificados según los criterios del club. | Curly apostrophe in the source preserved as-is in English |
| Use New evaluation to record the first one. | Use "Nueva evaluación" para registrar la primera. | Quotes added so the button name reads as a button name |
| Not scored | Sin calificar | |
| Delete the ${evaluation.season_label} evaluation? This cannot be undone. | ¿Eliminar la evaluación de ${evaluation.season_label}? Esta acción no se puede deshacer. | |
| What ${athleteName.split(' ')[0]}'s coaches have recorded this season and in past seasons. | Lo que los entrenadores de ${athleteName.split(' ')[0]} han registrado esta temporada y en temporadas anteriores. | |

### SHARED frontend/src/components/support/SupportDialog.tsx

| English | Español | Note |
|---|---|---|
| Close | Cerrar | |
| Tell us what you were doing and what happened. | Cuéntenos qué estaba haciendo y qué pasó. | |
| If we need more detail, someone will get in touch. | Si necesitamos más detalles, alguien se comunicará con usted. | |
| Done | Listo | |
| What went wrong? * | ¿Qué salió mal? * | Required-field asterisk preserved |
| Screenshot (optional) | Captura de pantalla (opcional) | |
| Cancel | Cancelar | |
| That image is still too large after resizing. Try a smaller one. | Esa imagen sigue siendo muy grande incluso después de reducirla. Pruebe con una más pequeña. | |
| Could not read that image | No se pudo leer esa imagen | |
| Could not send that. Please try again. | No se pudo enviar. Inténtelo de nuevo. | |
| Could not reach the server. Check your connection and try again. | No se pudo conectar con el servidor. Revise su conexión e inténtelo de nuevo. | |
| Thanks — we got it | Gracias, lo recibimos | |
| Report an issue | Reportar un problema | |
| Sending… | Enviando… | |
| Send report | Enviar reporte | |

### SHARED frontend/src/utils/consentStatus.ts

| English | Español | Note |
|---|---|---|
| Verified | Verificado | Consent rollup status |
| Agreed in the portal and confirmed by email. | Aceptado en el portal y confirmado por correo electrónico. | |
| Agreed | Aceptado | |
| Agreed in the portal. Waiting on the emailed confirmation link. | Aceptado en el portal. Falta confirmar con el enlace enviado por correo electrónico. | |
| Sign-up only | Solo en la inscripción | |
| Agreed on the registration form, but not yet confirmed from a portal account. | Aceptado en el formulario de inscripción, pero aún sin confirmar desde una cuenta del portal. | |
| Incomplete | Incompleto | |
| Some consents are on file, others are missing. | Hay algunos consentimientos registrados y faltan otros. | |
| Not on file | Sin registro | |
| No parental consent has been recorded for this athlete. | No se ha registrado ningún consentimiento de los padres para este atleta. | |
| Unknown | Desconocido | |
| Consent state could not be determined. | No se pudo determinar el estado del consentimiento. | |

### SHARED frontend/src/utils/jerseySize.ts

| English | Español | Note |
|---|---|---|
| Youth | Juvenil | Size group |
| Adult | Adulto | Size group |
| X-Small (4-5) | Extra chica (4-5) | Agrees with "camiseta"; age numbers preserved |
| Small (6-8) | Chica (6-8) | |
| Medium (10-12) | Mediana (10-12) | |
| Large (14-16) | Grande (14-16) | |
| X-Large (18-20) | Extra grande (18-20) | |
| X-Small | Extra chica | |
| Medium | Mediana | |
| Large | Grande | |
| X-Large | Extra grande | |
| 2X-Large | 2X grande | |
| 3X-Large | 3X grande | |

### SHARED frontend/src/utils/portalStatus.ts

| English | Español | Note |
|---|---|---|
| On the platform | En la plataforma | |
| Signed in at least once. | Ha iniciado sesión al menos una vez. | |
| Account never used | Cuenta nunca usada | |
| An account exists but nobody has ever signed into it. | La cuenta existe, pero nadie ha iniciado sesión en ella. | |
| Invited | Invitado | |
| Invite sent and still valid. They have not set a password yet. | Invitación enviada y aún vigente. Todavía no ha creado una contraseña. | |
| Invite expired | Invitación vencida | |
| They were invited but the link lapsed before they used it. Resend. | Se le envió una invitación, pero el enlace venció antes de que la usara. Vuelva a enviarla. | |
| Not invited | Sin invitación | |
| No invite has been sent. | No se ha enviado ninguna invitación. | |
| No email | Sin correo electrónico | |
| No address on file, so they cannot be invited at all. | No hay una dirección registrada, así que no se le puede invitar. | |
| Unknown | Desconocido | |
| Status could not be determined. | No se pudo determinar el estado. | |
| Created by an admin | Creada por un administrador | Agrees with "cuenta" (feminine) |
| Since ${first} | Desde ${first} | ⚠️ Assumed `${first}` is a date (first sign-in), not a name |
| Invited ${invited} | Invitado el ${invited} | ⚠️ Assumed `${invited}` is a date; if it can be a relative phrase ("2 days ago") drop the "el" |

---

## Excluded (not user-facing)

59 of 467 lines were not translated.

### (b) Developer-only — console/logged text, thrown internals, or code fragments that leaked from the extraction

| File | String | Reason |
|---|---|---|
| components/BrandingLogo.tsx | Failed to fetch branding | Thrown/logged internally; never rendered |
| components/BrandingLogo.tsx | Error fetching branding: | `console.error` prefix |
| chat/NewConversationDialog.tsx | Chat search failed: | `console.error` prefix |
| chat/NewConversationDialog.tsx | Failed to resolve team participants: | `console.error` prefix |
| chat/NewConversationDialog.tsx | Failed to resolve role group: | `console.error` prefix |
| parent-portal/ParentDashboard.tsx | Error fetching invoices: | `console.error` prefix |
| parent-portal/ParentDashboard.tsx | Error fetching events: | `console.error` prefix |
| parent-portal/ParentDashboard.tsx | Error fetching announcements: | `console.error` prefix |
| components/BottomNavigation.tsx | 0; return ( | Code fragment from the extraction |
| components/ConsentGate.tsx | ; registrationTypes: Set | TypeScript type fragment |
| components/ConsentGate.tsx | ; confirmedTypes: Set | TypeScript type fragment |
| components/ConsentGate.tsx | (), registrationTypes: new Set | Code fragment |
| components/ConsentGate.tsx | (), confirmedTypes: new Set | Code fragment |
| components/ParentErrorBoundary.tsx | Parent portal error boundary caught an error: | `console.error` prefix |
| components/SponsorMarquee.tsx | Failed to load sponsors: | `console.error` prefix |
| hooks/useParentAthletes.ts | Promise | Type name fragment |
| hooks/useParentAthletes.ts | Error fetching athlete ${athlete.id}: | `console.error` prefix |
| hooks/useParentAthletes.ts | Error fetching athlete details ${id}: | `console.error` prefix |
| pages/AnnouncementsPage.tsx | Error marking as read: | `console.error` prefix |
| pages/AthleteDetailPage.tsx | (data.success ? (data.coaches as Omit | Code fragment |
| pages/AthleteDetailPage.tsx | ) : athleteEvents.length === 0 ? ( | Code fragment |
| pages/AthleteDetailPage.tsx | ) : registrations.length === 0 ? ( | Code fragment |
| pages/AthleteDetailPage.tsx | Error fetching registrations: | `console.error` prefix |
| pages/AthleteDetailPage.tsx | Error fetching athlete events: | `console.error` prefix |
| pages/AthleteDetailPage.tsx | Error fetching coaches: | `console.error` prefix |
| pages/ScheduleRSVPPage.tsx | t.id === event.team_id)) : athletes; return ( | Code fragment |
| pages/VolunteerPage.tsx | ) : team.pending_signup ? ( | Code fragment |

### (c) Data placeholders, protocol values, keyboard keys and brand names

| File | String | Reason |
|---|---|---|
| components/BrandingLogo.tsx | TEAMS ELEVATED | Product name — not translated |
| pages/MoreMenuPage.tsx | Teams Elevated v1.0.0 | Product name + version string |
| chat/NewConversationDialog.tsx | Content-Type | HTTP header name |
| chat/NewConversationDialog.tsx | Bearer ${tokenRef.current ?? ''} | Authorization header value |
| chat/ReportMessageButton.tsx | Escape | Keyboard key name (`event.key`) |
| pages/TeamChatPage.tsx | Enter | Keyboard key name (`event.key`) |
| evaluations/AthleteEvaluationsPanel.tsx | Bearer ${localStorage.getItem('auth_token')} | Authorization header value |
| support/SupportDialog.tsx | Content-Type | HTTP header name |
| support/SupportDialog.tsx | Bearer ${token} | Authorization header value |
| parent-portal/ParentDashboard.tsx | Bearer ${token} | Authorization header value |
| components/ConsentGate.tsx | Content-Type | HTTP header name |
| components/ConsentGate.tsx | Bearer ${token} | Authorization header value |
| components/JerseySizeCard.tsx | Content-Type | HTTP header name |
| components/JerseySizeCard.tsx | Bearer ${token} | Authorization header value |
| components/SponsorMarquee.tsx | Bearer ${token} | Authorization header value |
| hooks/useParentAthletes.ts | Bearer ${token} | Authorization header value |
| pages/AnnouncementsPage.tsx | Content-Type | HTTP header name |
| pages/AnnouncementsPage.tsx | Bearer ${token} | Authorization header value |
| pages/AthleteDetailPage.tsx | Content-Type | HTTP header name |
| pages/AthleteDetailPage.tsx | Bearer ${token} | Authorization header value |
| pages/DocumentsPage.tsx | Bearer ${token} | Authorization header value |
| pages/MakePaymentPage.tsx | Content-Type | HTTP header name |
| pages/MakePaymentPage.tsx | Bearer ${token} | Authorization header value |
| pages/MedicalInfoPage.tsx | Content-Type | HTTP header name |
| pages/MedicalInfoPage.tsx | Bearer ${token} | Authorization header value |
| pages/PaymentStatusPage.tsx | Content-Type | HTTP header name |
| pages/PaymentStatusPage.tsx | Bearer ${token} | Authorization header value |
| pages/ScheduleRSVPPage.tsx | Content-Type | HTTP header name |
| pages/ScheduleRSVPPage.tsx | Bearer ${token} | Authorization header value |
| pages/UpcomingEventsPage.tsx | Bearer ${token} | Authorization header value |
| pages/VolunteerPage.tsx | Content-Type | HTTP header name |
| pages/VolunteerPage.tsx | Bearer ${token} | Authorization header value |
