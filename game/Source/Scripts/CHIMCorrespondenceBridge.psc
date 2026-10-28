Scriptname CHIMCorrespondenceBridge Hidden

; Physical receipts only. Recipient exposure belongs to the scoped server render/read path.
Function DispatchExternalCommand(String executorName, String command, String parameter) global
    If JContainers.APIVersion() != 3 || JContainers.featureVersion() < 3
        TraceReconcileDiagnostic("unsupported_jcontainers")
        Return
    EndIf
    If StringUtil.GetLength(parameter) > 16000
        TraceReconcileDiagnostic("payload_too_large")
        Return
    EndIf
    Int request = JValue.objectFromPrototype(parameter)
    If !JValue.isMap(request)
        TraceReconcileDiagnostic("malformed_payload")
        If request
            JValue.release(request)
        EndIf
        Return
    EndIf
    request = JValue.retain(request)
    If !CorrelationValid(request)
        TraceReconcileDiagnostic("invalid_correlation")
        JValue.release(request)
        Return
    EndIf
    If !RequestValid(request, parameter)
        Emit(request, "failed", "invalid_payload", 0, "[]")
    ElseIf command == "ExtCmdCHIMCorrespondenceBridge_Reconcile"
        Reconcile(request, parameter)
    ElseIf command == "ExtCmdCHIMCorrespondenceBridge_Deliver"
        ClaimAndDeliver(request, parameter)
    Else
        Emit(request, "failed", "unsupported_action", 0, "[]")
    EndIf
    JValue.release(request)
EndFunction

Function TraceReconcileDiagnostic(String reason) global
    Debug.Trace("[CHIM Correspondence] " + reason + "; use Check outcome (read-only Reconcile); do not resend")
EndFunction

Bool Function CorrelationValid(Int request) global
    If !JValue.isMap(request)
        Return false
    EndIf
    If JMap.valueType(request, "protocol_version") != 2 || JMap.getInt(request, "protocol_version") != 1
        Return false
    EndIf
    If !IsUUID(JMap.getStr(request, "document_id")) || !IsUUID(JMap.getStr(request, "operation_id")) || !IsUUID(JMap.getStr(request, "receipt_id"))
        Return false
    EndIf
    If !IsUUID(JMap.getStr(request, "scope_token")) || !IsHex(JMap.getStr(request, "nonce"), 64)
        Return false
    EndIf
    String scope = JMap.getStr(request, "playthrough_id")
    If scope == "unprofiled"
        Return true
    EndIf
    Int textLength = StringUtil.GetLength(scope)
    If textLength < 1 || textLength > 19 || StringUtil.GetNthChar(scope, 0) == "0"
        Return false
    EndIf
    Int i = 0
    While i < textLength
        Int character = StringUtil.AsOrd(StringUtil.GetNthChar(scope, i))
        If character < 48 || character > 57
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction

Bool Function RequestValid(Int request, String parameter) global
    If JMap.count(request) != 12 || !JValue.isMap(JMap.getObj(request, "recipient")) || !JValue.isMap(JMap.getObj(request, "payer"))
        Return false
    EndIf
    If !IdentityValid(JMap.getObj(request, "recipient"), "refid") || !IdentityValid(JMap.getObj(request, "payer"), "refid")
        Return false
    EndIf
    String title = JMap.getStr(request, "title")
    String titleDocumentID = StringUtil.Substring(title, 7)
    If JMap.valueType(request, "title") != 6 || StringUtil.GetLength(title) != 43 || StringUtil.Substring(title, 0, 7) != "letter " || !IsUUID(titleDocumentID) || !UUIDCaseEqual(titleDocumentID, JMap.getStr(request, "document_id"))
        Return false
    EndIf
    If !IsHex(JMap.getStr(request, "renderer_key"), 32)
        Return false
    EndIf
    Int attachments = JMap.getObj(request, "attachments")
    If !JValue.isArray(attachments) || JArray.count(attachments) > 8
        Return false
    EndIf
    Int i = 0
    While i < JArray.count(attachments)
        Int line = JArray.getObj(attachments, i)
        If !JValue.isMap(line) || JMap.count(line) != 5 || !IdentityValid(line, "form_id", 5)
            Return false
        EndIf
        If JMap.valueType(line, "quantity") != 2 || JMap.getInt(line, "quantity") < 1
            Return false
        EndIf
        String kind = JMap.getStr(line, "kind")
        If kind != "item" && kind != "gold"
            Return false
        EndIf
        If kind == "gold" && (JMap.getStr(line, "plugin") != "Skyrim.esm" || JMap.getInt(line, "local_id") != 15)
            Return false
        EndIf
        If kind == "item" && JMap.getStr(line, "plugin") == "Skyrim.esm" && JMap.getInt(line, "local_id") == 15
            Return false
        EndIf
        Int prior = 0
        While prior < i
            If JMap.getInt(JArray.getObj(attachments, prior), "form_id") == JMap.getInt(line, "form_id")
                Return false
            EndIf
            prior += 1
        EndWhile
        i += 1
    EndWhile
    ; JContainers normalizes JSON booleans. Reject non-integer lexemes and duplicate keys before trusting normalized values.
    Return JsonFieldCount(parameter) == 18 + 5 * JArray.count(attachments)
EndFunction

Bool Function IdentityValid(Int identity, String runtimeKey, Int fields = 3) global
    If JMap.count(identity) != fields || JMap.valueType(identity, runtimeKey) != 2 || JMap.getInt(identity, runtimeKey) == 0
        Return false
    EndIf
    If JMap.valueType(identity, "local_id") != 2 || JMap.getInt(identity, "local_id") < 1 || JMap.getInt(identity, "local_id") > 16777215
        Return false
    EndIf
    String plugin = JMap.getStr(identity, "plugin")
    If JMap.valueType(identity, "plugin") != 6 || StringUtil.GetLength(plugin) < 5 || StringUtil.GetLength(plugin) > 255
        Return false
    EndIf
    If StringUtil.Find(plugin, "/") >= 0 || StringUtil.Find(plugin, "\\") >= 0 || StringUtil.Find(plugin, ":") >= 0 || StringUtil.Find(plugin, "..") >= 0
        Return false
    EndIf
    Int i = 0
    While i < StringUtil.GetLength(plugin)
        If StringUtil.AsOrd(StringUtil.GetNthChar(plugin, i)) < 32
            Return false
        EndIf
        i += 1
    EndWhile
    String extension = StringUtil.Substring(plugin, StringUtil.GetLength(plugin) - 4)
    Return extension == ".esm" || extension == ".esp" || extension == ".esl"
EndFunction

; Count structural keys while excluding quoted text; JSON grammar itself is checked by the native parser.
Int Function JsonFieldCount(String parameter) global
    Bool quoted = false
    Bool escaped = false
    Int fields = 0
    Int i = 0
    While i < StringUtil.GetLength(parameter)
        String character = StringUtil.GetNthChar(parameter, i)
        If quoted
            If escaped
                escaped = false
            ElseIf character == "\\"
                escaped = true
            ElseIf character == "\""
                quoted = false
            EndIf
        ElseIf character == "\""
            quoted = true
        ElseIf character == ":"
            fields += 1
        ElseIf character == "-" || StringUtil.Find("0123456789", character) >= 0
            Int start = i
            i += 1
            While i < StringUtil.GetLength(parameter) && StringUtil.Find("0123456789", StringUtil.GetNthChar(parameter, i)) >= 0
                i += 1
            EndWhile
            If !IntegerLexemeValid(StringUtil.Substring(parameter, start, i - start))
                Return -1
            EndIf
            i -= 1
        ElseIf StringUtil.Find("{}[], ", character) < 0 && StringUtil.AsOrd(character) != 9 && StringUtil.AsOrd(character) != 10 && StringUtil.AsOrd(character) != 13
            Return -1
        EndIf
        i += 1
    EndWhile
    If quoted
        Return -1
    EndIf
    Return fields
EndFunction

Bool Function IntegerLexemeValid(String token) global
    String digits = token
    String bound = "2147483647"
    If StringUtil.GetNthChar(token, 0) == "-"
        digits = StringUtil.Substring(token, 1)
        bound = "2147483648"
    EndIf
    Int digitCount = StringUtil.GetLength(digits)
    If digitCount < 1 || digitCount > 10 || (digitCount > 1 && StringUtil.GetNthChar(digits, 0) == "0") || token == "-0"
        Return false
    EndIf
    If digitCount < 10
        Return true
    EndIf
    Int i = 0
    While i < 10
        Int digit = StringUtil.AsOrd(StringUtil.GetNthChar(digits, i))
        Int limit = StringUtil.AsOrd(StringUtil.GetNthChar(bound, i))
        If digit < limit
            Return true
        ElseIf digit > limit
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction
Bool Function IsHex(String value, Int textLength) global
    If StringUtil.GetLength(value) != textLength
        Return false
    EndIf
    Int i = 0
    While i < textLength
        Int character = StringUtil.AsOrd(StringUtil.GetNthChar(value, i))
        If !((character >= 48 && character <= 57) || (character >= 97 && character <= 102) || (character >= 65 && character <= 70))
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction

Bool Function IsUUID(String value) global
    If StringUtil.GetLength(value) != 36
        Return false
    EndIf
    Int i = 0
    While i < 36
        String character = StringUtil.GetNthChar(value, i)
        If i == 8 || i == 13 || i == 18 || i == 23
            If character != "-"
                Return false
            EndIf
        ElseIf !IsHex(character, 1)
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction

Bool Function UUIDCaseEqual(String leftValue, String rightValue) global
    If StringUtil.GetLength(leftValue) != 36 || StringUtil.GetLength(rightValue) != 36
        Return false
    EndIf
    Int i = 0
    While i < 36
        Int leftCharacter = StringUtil.AsOrd(StringUtil.GetNthChar(leftValue, i))
        Int rightCharacter = StringUtil.AsOrd(StringUtil.GetNthChar(rightValue, i))
        If leftCharacter >= 65 && leftCharacter <= 70
            leftCharacter += 32
        EndIf
        If rightCharacter >= 65 && rightCharacter <= 70
            rightCharacter += 32
        EndIf
        If leftCharacter != rightCharacter
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction

Form Function Resolve(Int identity, String runtimeKey) global
    Form resolved = Game.GetFormFromFile(JMap.getInt(identity, "local_id"), JMap.getStr(identity, "plugin"))
    If !resolved || resolved.GetFormID() != JMap.getInt(identity, runtimeKey)
        Return None
    EndIf
    ; Dynamic references have no static plugin/local identity, so the equality check rejects them.
    Return resolved
EndFunction

String Function JournalPath(Int request) global
    Return ".chim_correspondence_v1.p" + JMap.getStr(request, "playthrough_id") + ".s" + JMap.getStr(request, "scope_token") + ".o" + JMap.getStr(request, "operation_id")
EndFunction

Function ClaimAndDeliver(Int request, String parameter) global
    Int candidate = JValue.retain(JMap.object())
    JMap.setStr(candidate, "payload", parameter)
    JMap.setStr(candidate, "status", "in_progress")
    JMap.setInt(candidate, "note_form_id", 0)
    JMap.setStr(candidate, "actual", "[]")
    String path = JournalPath(request)
    Int existing = JAtomic.compareExchangeObj(JDB.root(), path, candidate, 0, true, -1)
    If existing == -1
        Emit(request, "unknown", "journal_unavailable", 0, "[]")
    ElseIf existing != 0
        Replay(request, parameter, existing)
    Else
        Deliver(request, candidate)
    EndIf
    JValue.release(candidate)
EndFunction

Function Reconcile(Int request, String parameter) global
    Int existing = JDB.solveObj(JournalPath(request))
    If existing == 0
        Emit(request, "unknown", "journal_missing", 0, "[]")
    Else
        Replay(request, parameter, existing)
    EndIf
EndFunction

Function Replay(Int request, String parameter, Int existing) global
    If JMap.getStr(existing, "payload") != parameter
        Emit(request, "failed", "operation_conflict", 0, "[]")
        Return
    EndIf
    String receipt = JMap.getStr(existing, "receipt")
    If receipt != ""
        AIAgentFunctions.logMessage(receipt, "correspondence_receipt")
    Else
        Emit(request, "unknown", "in_progress", JMap.getInt(existing, "note_form_id"), JMap.getStr(existing, "actual", "[]"))
    EndIf
EndFunction

String Function Preflight(Int request, Actor payer, Actor recipient) global
    If !payer || !recipient || !recipient.GetActorBase() || (payer == recipient && JArray.count(JMap.getObj(request, "attachments")) > 0)
        Return "invalid_actor"
    EndIf
    If payer.IsDead() || recipient.IsDead() || payer.IsDisabled() || recipient.IsDisabled() || payer.IsDeleted() || recipient.IsDeleted()
        Return "actor_unavailable"
    EndIf
    Book template = Game.GetFormFromFile(0x022D30, "AIAgent.esp") as Book
    If !template || template.GetName() != "Generic Note"
        Return "note_dependency_unavailable"
    EndIf
    If recipient.GetItemCount(template) < 0 || recipient.GetItemCount(template) == 2147483647
        Return "inventory_overflow"
    EndIf
    If !JContainers.fileExistsAtPath("Data/textures/AIAgent/Books/" + JMap.getStr(request, "renderer_key") + ".png")
        Return "renderer_not_ready"
    EndIf
    Int[] extenderVersion = PO3_SKSEFunctions.GetPapyrusExtenderVersion()
    If extenderVersion.Length < 3
        Return "papyrus_extender_unavailable"
    EndIf
    Form[] questItems = PO3_SKSEFunctions.GetQuestItems(payer)
    Int attachments = JMap.getObj(request, "attachments")
    Int i = 0
    While i < JArray.count(attachments)
        Int line = JArray.getObj(attachments, i)
        Form item = Resolve(line, "form_id")
        If !item || !item.IsPlayable()
            Return "invalid_item"
        EndIf
        If payer.IsEquipped(item) || questItems.Find(item) >= 0
            Return "protected_item"
        EndIf
        Int requested = JMap.getInt(line, "quantity")
        Int beforeSource = payer.GetItemCount(item)
        Int beforeRecipient = recipient.GetItemCount(item)
        If beforeSource < requested
            Return "insufficient_inventory"
        EndIf
        If beforeRecipient < 0 || beforeRecipient > 2147483647 - requested
            Return "inventory_overflow"
        EndIf
        i += 1
    EndWhile
    Return ""
EndFunction

Function Deliver(Int request, Int journal) global
    Actor payer = Resolve(JMap.getObj(request, "payer"), "refid") as Actor
    Actor recipient = Resolve(JMap.getObj(request, "recipient"), "refid") as Actor
    String reason = Preflight(request, payer, recipient)
    If reason != ""
        Finish(request, journal, "failed", reason, 0, "[]")
        Return
    EndIf
    Book template = Game.GetFormFromFile(0x022D30, "AIAgent.esp") as Book
    Int notesBefore = recipient.GetItemCount(template)
    Int expectedNotes = notesBefore + 1
    ObjectReference note = recipient.PlaceAtMe(template, 1, true, true)
    If !note
        Finish(request, journal, "unknown", "note_creation_unknown", 0, "[]")
        Return
    EndIf
    JMap.setForm(journal, "note", note)
    JMap.setInt(journal, "note_form_id", note.GetFormID())
    note.SetDisplayName(JMap.getStr(request, "title"), true)
    note.SetActorOwner(recipient.GetActorBase())
    recipient.AddItem(note, 1, true)
    note.Enable()
    If recipient.GetItemCount(template) != expectedNotes || note.GetDisplayName() != JMap.getStr(request, "title") || note.GetActorOwner() != recipient.GetActorBase()
        Finish(request, journal, "unknown", "note_ownership_unknown", note.GetFormID(), "[]")
        Return
    EndIf
    Int attachments = JMap.getObj(request, "attachments")
    String actual = "["
    Int i = 0
    While i < JArray.count(attachments)
        Int line = JArray.getObj(attachments, i)
        Form item = Resolve(line, "form_id")
        Int requested = JMap.getInt(line, "quantity")
        Int beforeSource = payer.GetItemCount(item)
        Int beforeRecipient = recipient.GetItemCount(item)
        ; Recheck after latent engine calls; uncertainty after note creation must never trigger automatic redelivery.
        If !item || payer.IsDead() || recipient.IsDead() || payer.IsDisabled() || recipient.IsDisabled() || beforeSource < requested || payer.IsEquipped(item) || PO3_SKSEFunctions.GetQuestItems(payer).Find(item) >= 0 || beforeRecipient > 2147483647 - requested
            Finish(request, journal, "unknown", "inventory_changed", note.GetFormID(), actual + "]")
            Return
        EndIf
        payer.RemoveItem(item, requested, true, recipient)
        Int afterSource = payer.GetItemCount(item)
        Int afterRecipient = recipient.GetItemCount(item)
        If i > 0
            actual += ","
        EndIf
        actual += "{\"form_id\":" + item.GetFormID() + ",\"quantity\":" + (afterRecipient - beforeRecipient) + ",\"before_source\":" + beforeSource + ",\"after_source\":" + afterSource + ",\"before_recipient\":" + beforeRecipient + ",\"after_recipient\":" + afterRecipient + "}"
        JMap.setStr(journal, "actual", actual + "]")
        If beforeSource - afterSource != requested || afterRecipient - beforeRecipient != requested
            Finish(request, journal, "unknown", "transfer_mismatch", note.GetFormID(), actual + "]")
            Return
        EndIf
        If item == template
            expectedNotes += requested
        EndIf
        i += 1
    EndWhile
    If recipient.GetItemCount(template) != expectedNotes || note.GetDisplayName() != JMap.getStr(request, "title") || note.GetActorOwner() != recipient.GetActorBase()
        Finish(request, journal, "unknown", "note_ownership_unknown", note.GetFormID(), actual + "]")
        Return
    EndIf
    Finish(request, journal, "delivered", "physical_only", note.GetFormID(), actual + "]")
EndFunction

Function Finish(Int request, Int journal, String status, String reason, Int noteID, String actual) global
    String receipt = ReceiptJSON(request, status, reason, noteID, actual)
    JMap.setStr(journal, "receipt", receipt)
    JMap.setStr(journal, "status", status)
    AIAgentFunctions.logMessage(receipt, "correspondence_receipt")
EndFunction

Function Emit(Int request, String status, String reason, Int noteID, String actual) global
    AIAgentFunctions.logMessage(ReceiptJSON(request, status, reason, noteID, actual), "correspondence_receipt")
EndFunction

String Function ReceiptJSON(Int request, String status, String reason, Int noteID, String actual) global
    ; Only validated correlation strings and fixed codes enter this JSON; names/title/provenance never enter it.
    Return "{\"protocol_version\":1,\"document_id\":\"" + JMap.getStr(request, "document_id") + "\",\"operation_id\":\"" + JMap.getStr(request, "operation_id") + "\",\"receipt_id\":\"" + JMap.getStr(request, "receipt_id") + "\",\"playthrough_id\":\"" + JMap.getStr(request, "playthrough_id") + "\",\"scope_token\":\"" + JMap.getStr(request, "scope_token") + "\",\"nonce\":\"" + JMap.getStr(request, "nonce") + "\",\"status\":\"" + status + "\",\"reason\":\"" + reason + "\",\"note_form_id\":" + noteID + ",\"attachments\":" + actual + "}"
EndFunction







