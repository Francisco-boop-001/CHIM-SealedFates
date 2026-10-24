Scriptname CHIMCorrespondenceCompanion Hidden

Function DispatchExternalCommand(String executorName, String command, String parameter) global
    If command != "ExtCmdCHIMCorrespondenceCompanion_Observe"
        Return
    EndIf
    If JContainers.APIVersion() != 3 || JContainers.featureVersion() < 3 || StringUtil.GetLength(parameter) > 16000
        Return
    EndIf
    Int request = JValue.objectFromPrototype(parameter)
    If !JValue.isMap(request)
        If request
            JValue.release(request)
        EndIf
        Return
    EndIf
    request = JValue.retain(request)
    If RequestValid(request, parameter)
        String response = Observe(request)
        If response != ""
            AIAgentFunctions.logMessage(response, "correspondence_companion")
        EndIf
    EndIf
    JValue.release(request)
EndFunction

Bool Function RequestValid(Int request, String parameter) global
    If JMap.count(request) != 6 || JMap.valueType(request, "version") != 2 || JMap.getInt(request, "version") != 1
        Return false
    EndIf
    If !CHIMCorrespondenceBridge.IsUUID(JMap.getStr(request, "observation_id")) || !CHIMCorrespondenceBridge.IsUUID(JMap.getStr(request, "epoch"))
        Return false
    EndIf
    If !ScopeValid(JMap.getStr(request, "playthrough_id")) || !LowerHexValid(JMap.getStr(request, "nonce"), 64)
        Return false
    EndIf
    Int actors = JMap.getObj(request, "actors")
    If !JValue.isArray(actors) || JArray.count(actors) < 1 || JArray.count(actors) > 4
        Return false
    EndIf
    Int i = 0
    While i < JArray.count(actors)
        Int actor = JArray.getObj(actors, i)
        If !JValue.isMap(actor) || !ActorIdValid(JMap.getStr(actor, "id")) || !CHIMCorrespondenceBridge.IdentityValid(actor, "refid", 4)
            Return false
        EndIf
        Int prior = 0
        While prior < i
            Int previous = JArray.getObj(actors, prior)
            If JMap.getStr(previous, "id") == JMap.getStr(actor, "id") || JMap.getInt(previous, "refid") == JMap.getInt(actor, "refid")
                Return false
            EndIf
            prior += 1
        EndWhile
        i += 1
    EndWhile
    ; Count raw keys to reject duplicate JSON keys and normalized booleans/floats.
    Return CHIMCorrespondenceBridge.JsonFieldCount(parameter) == 6 + 4 * JArray.count(actors)
EndFunction

Bool Function ActorIdValid(String value) global
    Int textLength = StringUtil.GetLength(value)
    If textLength < 1 || textLength > 19 || StringUtil.GetNthChar(value, 0) == "0"
        Return false
    EndIf
    Int i = 0
    While i < textLength
        Int character = StringUtil.AsOrd(StringUtil.GetNthChar(value, i))
        If character < 48 || character > 57
            Return false
        EndIf
        i += 1
    EndWhile
    If textLength == 19 && !DecimalAtMostSigned64(value)
        Return false
    EndIf
    Return true
EndFunction

Bool Function DecimalAtMostSigned64(String value) global
    String bound = "9223372036854775807"
    Int i = 0
    While i < 19
        Int digit = StringUtil.AsOrd(StringUtil.GetNthChar(value, i))
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

Bool Function ScopeValid(String value) global
    Int textLength = StringUtil.GetLength(value)
    If textLength < 1 || textLength > 64
        Return false
    EndIf
    Int i = 0
    While i < textLength
        Int character = StringUtil.AsOrd(StringUtil.GetNthChar(value, i))
        If !((character >= 48 && character <= 57) || (character >= 65 && character <= 90) || (character >= 97 && character <= 122) || character == 45 || character == 95)
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction

Bool Function LowerHexValid(String value, Int textLength) global
    If !CHIMCorrespondenceBridge.IsHex(value, textLength)
        Return false
    EndIf
    Int i = 0
    While i < textLength
        Int character = StringUtil.AsOrd(StringUtil.GetNthChar(value, i))
        If character >= 65 && character <= 70
            Return false
        EndIf
        i += 1
    EndWhile
    Return true
EndFunction

String Function Observe(Int request) global
    Int actors = JMap.getObj(request, "actors")
    Actor player = Game.GetPlayer()
    Faction waitFaction = Game.GetFormFromFile(0x02021E, "AIAgent.esp") as Faction
    Package waitPackage = Game.GetFormFromFile(0x02021F, "AIAgent.esp") as Package
    Package softWaitPackage = Game.GetFormFromFile(0x0268B1, "AIAgent.esp") as Package
    Int gameMinute = CurrentGameMinute()
    If gameMinute < 0
        Return ""
    EndIf
    String playthroughID = JMap.getStr(request, "playthrough_id")
    String epoch = JMap.getStr(request, "epoch")
    Int stateNamespace = GetStateNamespace(playthroughID, epoch)
    If !stateNamespace
        Return ""
    EndIf
    String samples = "["
    Int i = 0
    While i < JArray.count(actors)
        Int identity = JArray.getObj(actors, i)
        String actorID = JMap.getStr(identity, "id")
        Int refid = JMap.getInt(identity, "refid")
        Actor subject = CHIMCorrespondenceBridge.Resolve(identity, "refid") as Actor
        String sampleState = "unknown"
        If gameMinute >= 0
            sampleState = ClassifyActorState(subject, player, waitFaction, waitPackage, softWaitPackage)
        EndIf
        String episode = ""
        Int firstMinute = 0
        String path = StatePath(playthroughID, epoch, actorID)
        If sampleState == "waiting"
            Int stateMap = GetEpisodeState(stateNamespace, path, true)
            If !stateMap
                sampleState = "unknown"
            Else
                episode = RecordWaitingEpisode(stateMap, JMap.getStr(request, "observation_id"), gameMinute)
                If episode == ""
                    sampleState = "unknown"
                Else
                    firstMinute = JMap.getInt(stateMap, "first_game_minute")
                EndIf
                JValue.release(stateMap)
            EndIf
        Else
            ClearEpisode(stateNamespace, path)
        EndIf
        If sampleState != "waiting"
            episode = ""
            firstMinute = 0
        EndIf
        If i > 0
            samples += ","
        EndIf
        samples += "{\"id\":\"" + actorID + "\",\"refid\":" + refid + ",\"state\":\"" + sampleState + "\",\"episode\":\"" + episode + "\",\"first_game_minute\":" + firstMinute + ",\"game_minute\":" + gameMinute + "}"
        i += 1
    EndWhile
    samples += "]"
    JValue.release(stateNamespace)
    Return "{\"version\":1,\"observation_id\":\"" + JMap.getStr(request, "observation_id") + "\",\"playthrough_id\":\"" + playthroughID + "\",\"epoch\":\"" + epoch + "\",\"nonce\":\"" + JMap.getStr(request, "nonce") + "\",\"actors\":" + samples + "}"
EndFunction

Int Function CurrentGameMinute() global
    Float current = Utility.GetCurrentGameTime()
    If current < 0.0
        Return -1
    EndIf
    Return (current * 1440.0) as Int
EndFunction

String Function ClassifyActorState(Actor subject, Actor player, Faction waitFaction, Package waitPackage, Package softWaitPackage) global
    If !subject
        Return "unknown"
    EndIf
    If subject.IsDead()
        Return "dead"
    EndIf
    If !player
        Return "unknown"
    EndIf
    Cell subjectCell = subject.GetParentCell()
    Cell playerCell = player.GetParentCell()
    If !subjectCell || !playerCell
        Return "unknown"
    EndIf
    If subjectCell == playerCell
        If !subject.Is3DLoaded() || !player.Is3DLoaded()
            Return "unknown"
        EndIf
        Float distance = subject.GetDistance(player)
        If distance <= 1024.0
            Return "nearby"
        ElseIf distance <= 2048.0
            Return "unknown"
        EndIf
    EndIf
    If !subject.IsPlayerTeammate()
        Return "other"
    EndIf
    If subject.GetActorValue("WaitingForPlayer") > 0.0
        Return "waiting"
    EndIf
    If waitFaction && subject.IsInFaction(waitFaction)
        Package currentPackage = subject.GetCurrentPackage()
        If (waitPackage && currentPackage == waitPackage) || (softWaitPackage && currentPackage == softWaitPackage)
            Return "waiting"
        EndIf
    EndIf
    Return "other"
EndFunction

Bool Function EpisodeCanContinue(String episode, Int firstMinute, Int lastMinute, Int currentMinute) global
    If !CHIMCorrespondenceBridge.IsUUID(episode) || firstMinute < 0 || lastMinute < firstMinute || currentMinute < lastMinute
        Return false
    EndIf
    If currentMinute - lastMinute > 120
        Return false
    EndIf
    Return true
EndFunction

String Function RecordWaitingEpisode(Int stateMap, String observationID, Int gameMinute) global
    If !JValue.isMap(stateMap) || !CHIMCorrespondenceBridge.IsUUID(observationID) || gameMinute < 0
        Return ""
    EndIf
    String episode = JMap.getStr(stateMap, "episode")
    Int firstMinute = JMap.getInt(stateMap, "first_game_minute", -1)
    Int lastMinute = JMap.getInt(stateMap, "last_game_minute", -1)
    If !EpisodeCanContinue(episode, firstMinute, lastMinute, gameMinute)
        episode = observationID
        firstMinute = gameMinute
    EndIf
    JMap.setStr(stateMap, "episode", episode)
    JMap.setInt(stateMap, "first_game_minute", firstMinute)
    JMap.setInt(stateMap, "last_game_minute", gameMinute)
    Return episode
EndFunction

Bool Function ClearEpisode(Int stateNamespace, String stateKey) global
    If !JValue.isMap(stateNamespace) || stateKey == ""
        Return false
    EndIf
    Return JMap.removeKey(stateNamespace, stateKey)
EndFunction

Int Function GetStateNamespace(String playthroughID, String epoch) global
    String rootPath = ".chim_correspondence_companion_v1"
    Int stored = JDB.solveObj(rootPath)
    If stored && JValue.isMap(stored)
        Int stateNamespace = JValue.retain(stored)
        If !PrepareStateNamespace(stateNamespace, playthroughID, epoch)
            JValue.release(stateNamespace)
            Return 0
        EndIf
        Return stateNamespace
    EndIf
    If stored
        Return 0
    EndIf
    Int candidate = JValue.retain(JMap.object())
    Int existing = JAtomic.compareExchangeObj(JDB.root(), rootPath, candidate, 0, true, -1)
    If existing == -1
        JValue.release(candidate)
        Return 0
    EndIf
    If existing != 0
        JValue.release(candidate)
        If JValue.isMap(existing)
            Int stateNamespace = JValue.retain(existing)
            If !PrepareStateNamespace(stateNamespace, playthroughID, epoch)
                JValue.release(stateNamespace)
                Return 0
            EndIf
            Return stateNamespace
        EndIf
        Return 0
    EndIf
    If !PrepareStateNamespace(candidate, playthroughID, epoch)
        JValue.release(candidate)
        Return 0
    EndIf
    Return candidate
EndFunction

Bool Function ScopeEpochMatches(String savedPlaythroughID, String savedEpoch, String playthroughID, String epoch) global
    Return savedPlaythroughID == playthroughID && savedEpoch == epoch
EndFunction

Bool Function PrepareStateNamespace(Int stateNamespace, String playthroughID, String epoch) global
    If !JValue.isMap(stateNamespace) || !ScopeValid(playthroughID) || !CHIMCorrespondenceBridge.IsUUID(epoch)
        Return false
    EndIf
    If !ScopeEpochMatches(JMap.getStr(stateNamespace, "active_playthrough_id"), JMap.getStr(stateNamespace, "active_epoch"), playthroughID, epoch)
        JMap.clear(stateNamespace)
        JMap.setStr(stateNamespace, "active_playthrough_id", playthroughID)
        JMap.setStr(stateNamespace, "active_epoch", epoch)
    EndIf
    Return true
EndFunction

Int Function GetEpisodeState(Int stateNamespace, String stateKey, Bool createIfMissing) global
    If !JValue.isMap(stateNamespace) || stateKey == ""
        Return 0
    EndIf
    Int stored = JMap.getObj(stateNamespace, stateKey)
    If stored && JValue.isMap(stored)
        Return JValue.retain(stored)
    EndIf
    If stored
        JMap.removeKey(stateNamespace, stateKey)
    EndIf
    If !createIfMissing
        Return 0
    EndIf
    Int candidate = JValue.retain(JMap.object())
    Int existing = JAtomic.compareExchangeObj(stateNamespace, "." + stateKey, candidate, 0, true, -1)
    If existing == -1
        JValue.release(candidate)
        Return 0
    EndIf
    If existing != 0
        JValue.release(candidate)
        If JValue.isMap(existing)
            Return JValue.retain(existing)
        EndIf
        Return 0
    EndIf
    Return candidate
EndFunction

String Function StatePath(String playthroughID, String epoch, String actorID) global
    Return "p" + playthroughID + "_e" + epoch + "_a" + actorID
EndFunction
