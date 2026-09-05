import React, { useState, useEffect } from 'react';
import { Target, Trophy, X, BookOpen, Zap, RotateCcw, CheckCircle, Home } from 'lucide-react';

/**
 * LANGUAGE LEARNING PLATFORM — TEMPLATE
 * -------------------------------------
 * To adapt this for a new language / topic:
 * 1. Change LANGUAGE_NAME below.
 * 2. Replace the two example entries in `vocabularySets` with real content —
 *    keep the same shape: { word, english, pronunciation }.
 * 3. Append new sets at the end (numbered keys), never renumber existing ones.
 * 4. That's it — Flashcards, Shooting Gallery, and AI Chat all iterate over
 *    `vocabularySets` generically, so no other wiring is needed per set.
 */
const LANGUAGE_NAME = "Spanish"; // e.g. "French", "German", "Spanish"...

/**
 * Who is using this. The AI Chat prompts read from here, so the model pitches
 * at the right age and syllabus instead of a hard-coded "12-year-old Key Stage 3".
 */
const STUDENT = { age: 13, level: `GCSE (AQA 8692) ${LANGUAGE_NAME}` };

const LanguageLearningApp = () => {
  const [currentScreen, setCurrentScreen] = useState('welcome');
  const [selectedSet, setSelectedSet] = useState(1);

  const vocabularySets = {
    1: {
      name: "Example Set — Greetings",
      words: [
        { word: 'hola', english: 'hello', pronunciation: 'OH-la' },
        { word: 'adiós', english: 'goodbye', pronunciation: 'a-dee-OS' },
        { word: 'por favor', english: 'please', pronunciation: 'por fa-VOR' },
        { word: 'gracias', english: 'thank you', pronunciation: 'GRA-see-as' },
        { word: 'sí', english: 'yes', pronunciation: 'see' },
        { word: 'no', english: 'no', pronunciation: 'no' },
      ]
    },
    2: {
      name: "Example Set — Numbers 1-10",
      words: [
        { word: 'uno', english: 'one', pronunciation: 'OO-no' },
        { word: 'dos', english: 'two', pronunciation: 'dos' },
        { word: 'tres', english: 'three', pronunciation: 'tres' },
        { word: 'cuatro', english: 'four', pronunciation: 'KWA-tro' },
        { word: 'cinco', english: 'five', pronunciation: 'THEEN-ko' },
        { word: 'seis', english: 'six', pronunciation: 'says' },
        { word: 'siete', english: 'seven', pronunciation: 'see-EH-tay' },
        { word: 'ocho', english: 'eight', pronunciation: 'OH-cho' },
        { word: 'nueve', english: 'nine', pronunciation: 'noo-EH-vay' },
        { word: 'diez', english: 'ten', pronunciation: 'dee-ETH' },
      ]
    }
  };

  // Get current vocabulary based on selected set
  const getCurrentVocabulary = () => vocabularySets[selectedSet].words;
  
  // Get previous sets vocabulary (for mixing in games)
  const getPreviousVocabulary = () => {
    const allPrevious = [];
    for (let i = 1; i < selectedSet; i++) {
      if (vocabularySets[i]) {
        allPrevious.push(...vocabularySets[i].words);
      }
    }
    return allPrevious;
  };

  // Get mixed vocabulary for games.
  //
  // currentPercent is the share of the pool the CURRENT set should make up, so
  // every current word must be in the pool and earlier words are added on top
  // until the proportion is right. The previous version sliced the current set
  // to `length * currentPercent` and dropped the rest, so with a second set in
  // play the last 20% of every set never appeared in the gallery and the last
  // 40% never in the chat — words that could never be practised and could never
  // be logged as missed.
  const getMixedVocabulary = (currentPercent) => {
    const current = getCurrentVocabulary();
    const previous = getPreviousVocabulary();

    if (previous.length === 0) {
      return current; // If no previous sets, just use current
    }

    // n current words are currentPercent of (n + extra) → extra = n * (1/pct - 1)
    const extra = Math.max(0, Math.ceil(current.length * (1 / currentPercent - 1)));
    const mixed = [...current];

    // Add random words from previous sets on top of, never instead of, the set
    for (let i = 0; i < extra; i++) {
      mixed.push(previous[Math.floor(Math.random() * previous.length)]);
    }

    return mixed;
  };
  
  // ---- reporting to the tracker -------------------------------------------
  //
  // The app cannot reach the tracker itself: it runs inside a sandbox that can
  // only call api.anthropic.com. So each finished run produces the exact
  // tracker_log_practice payload, and Claude pastes it in at the end of the
  // session. Without this the numbers were retyped from memory, which is how
  // one afternoon came to be recorded twice with two different totals.
  const copyText = (text) => {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(() => {});
        return;
      }
    } catch (err) { /* fall through to the textarea route */ }
    try {
      const el = document.createElement('textarea');
      el.value = text;
      el.setAttribute('readonly', '');
      el.style.position = 'absolute';
      el.style.left = '-9999px';
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    } catch (err) { /* clipboard unavailable; the JSON is on screen anyway */ }
  };

  const buildRunReport = ({ source, label, startedAt, attempted, correct,
                            correctAfterRetry = 0, incorrect, durationSeconds,
                            metrics = {}, items = [], topicRefs = [] }) => ({
    subject: 'spanish',
    runs: [{
      client_run_id: `${source}-set${selectedSet}-${startedAt}`,
      source,
      label,
      played_at: startedAt,
      attempted,
      correct,
      correct_after_retry: correctAfterRetry,
      incorrect,
      ...(durationSeconds != null ? { duration_seconds: durationSeconds } : {}),
      ...(topicRefs.length ? { topic_refs: topicRefs } : {}),
      metrics,
      items: items.map(i => Object.fromEntries(
        Object.entries(i).filter(([, v]) => v !== undefined && v !== null)
      )),
    }],
  });

  const vocabulary = getCurrentVocabulary();

  // Welcome Screen
  const WelcomeScreen = () => {
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const dropdownRef = React.useRef(null);

    // Close dropdown when clicking outside
    React.useEffect(() => {
      const handleClickOutside = (event) => {
        if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
          setDropdownOpen(false);
          setSearchTerm('');
        }
      };

      if (dropdownOpen) {
        document.addEventListener('mousedown', handleClickOutside);
      }

      return () => {
        document.removeEventListener('mousedown', handleClickOutside);
      };
    }, [dropdownOpen]);

    const filteredSets = Object.keys(vocabularySets).filter(setNum => {
      const set = vocabularySets[setNum];
      const searchLower = searchTerm.toLowerCase();
      return (
        `set ${setNum}`.includes(searchLower) ||
        set.name.toLowerCase().includes(searchLower) ||
        `${set.words.length} words`.includes(searchLower)
      );
    });

    return (
      <div className="min-h-screen bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-600 flex items-center justify-center p-8">
        <div className="bg-white rounded-3xl shadow-2xl p-12 max-w-4xl w-full">
          <h1 className="text-6xl font-bold text-center mb-4 bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent">
            {LANGUAGE_NAME} Learning Platform! 🎮
          </h1>
          <p className="text-xl text-gray-600 text-center mb-8">
            Choose your learning adventure
          </p>

          {/* Word Set Selector - Searchable Dropdown */}
          <div className="mb-8 bg-gradient-to-r from-blue-50 to-purple-50 rounded-2xl p-6">
            <h2 className="text-xl font-bold text-gray-800 mb-4 text-center">Select Your Vocabulary Set:</h2>
            
            <div className="relative max-w-2xl mx-auto" ref={dropdownRef}>
              {/* Selected Set Display / Dropdown Trigger */}
              <button
                onClick={() => setDropdownOpen(!dropdownOpen)}
                className="w-full bg-white border-2 border-purple-300 rounded-xl p-4 text-left flex items-center justify-between hover:border-purple-500 transition-colors shadow-md"
              >
                <div>
                  <span className="font-bold text-lg text-purple-600">Set {selectedSet}</span>
                  <span className="text-gray-700 mx-2">-</span>
                  <span className="text-gray-700">{vocabularySets[selectedSet].name}</span>
                  <span className="text-gray-500 text-sm ml-2">
                    ({vocabularySets[selectedSet].words.length} words)
                  </span>
                </div>
                <svg
                  className={`w-5 h-5 text-purple-600 transition-transform ${dropdownOpen ? 'rotate-180' : ''}`}
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              {/* Dropdown Menu */}
              {dropdownOpen && (
                <div className="absolute z-10 w-full mt-2 bg-white border-2 border-purple-300 rounded-xl shadow-2xl max-h-96 overflow-hidden">
                  {/* Search Input */}
                  <div className="p-3 border-b border-gray-200 sticky top-0 bg-white">
                    <input
                      type="text"
                      placeholder="Search sets..."
                      value={searchTerm}
                      onChange={(e) => setSearchTerm(e.target.value)}
                      className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500"
                      onClick={(e) => e.stopPropagation()}
                    />
                  </div>

                  {/* Options List */}
                  <div className="max-h-80 overflow-y-auto">
                    {filteredSets.length > 0 ? (
                      filteredSets.map(setNum => (
                        <button
                          key={setNum}
                          onClick={() => {
                            setSelectedSet(parseInt(setNum));
                            setDropdownOpen(false);
                            setSearchTerm('');
                          }}
                          className={`w-full text-left px-4 py-3 hover:bg-purple-50 transition-colors border-b border-gray-100 ${
                            selectedSet === parseInt(setNum) ? 'bg-purple-100 font-semibold' : ''
                          }`}
                        >
                          <span className="font-bold text-purple-600">Set {setNum}</span>
                          <span className="text-gray-700 mx-2">-</span>
                          <span className="text-gray-700">{vocabularySets[setNum].name}</span>
                          <span className="text-gray-500 text-sm ml-2">
                            ({vocabularySets[setNum].words.length} words)
                          </span>
                        </button>
                      ))
                    ) : (
                      <div className="px-4 py-8 text-center text-gray-500">
                        No sets found matching "{searchTerm}"
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>
        
        <div className="grid md:grid-cols-3 gap-6">
          {/* Flashcards Option */}
          <button
            onClick={() => setCurrentScreen('flashcards')}
            className="bg-gradient-to-br from-blue-400 to-blue-600 rounded-2xl p-8 text-white hover:shadow-2xl transform hover:scale-105 transition-all group"
          >
            <BookOpen size={64} className="mx-auto mb-4 group-hover:rotate-12 transition-transform" />
            <h2 className="text-3xl font-bold mb-3">Flashcards</h2>
            <p className="text-blue-100 mb-4">
              Learn at your own pace with interactive flip cards
            </p>
            <div className="bg-white bg-opacity-20 rounded-lg p-3 text-sm">
              ✓ Click to reveal translations<br/>
              ✓ Track your progress<br/>
              ✓ Perfect for beginners
            </div>
          </button>

          {/* Shooting Gallery Option */}
          <button
            onClick={() => setCurrentScreen('shooting-gallery')}
            className="bg-gradient-to-br from-purple-500 to-pink-600 rounded-2xl p-8 text-white hover:shadow-2xl transform hover:scale-105 transition-all group"
          >
            <Target size={64} className="mx-auto mb-4 group-hover:rotate-12 transition-transform" />
            <h2 className="text-3xl font-bold mb-3">Shooting Gallery</h2>
            <p className="text-purple-100 mb-4">
              Fast-paced action game to test your skills
            </p>
            <div className="bg-white bg-opacity-20 rounded-lg p-3 text-sm">
              ✓ Shoot down {LANGUAGE_NAME} words<br/>
              ✓ Gets faster as you go<br/>
              ✓ Challenge yourself!
            </div>
          </button>

          {/* AI Chat Option */}
          <button
            onClick={() => setCurrentScreen('ai-chat')}
            className="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-8 text-white hover:shadow-2xl transform hover:scale-105 transition-all group"
          >
            <Zap size={64} className="mx-auto mb-4 group-hover:rotate-12 transition-transform" />
            <h2 className="text-3xl font-bold mb-3">AI Chat</h2>
            <p className="text-emerald-100 mb-4">
              Practice conversations with an AI friend
            </p>
            <div className="bg-white bg-opacity-20 rounded-lg p-3 text-sm">
              ✓ Real conversations<br/>
              ✓ AI checks your answers<br/>
              ✓ Adaptive learning
            </div>
          </button>
        </div>

        <div className="mt-8 text-center text-gray-600">
          <p className="text-lg">{STUDENT.level} • Vocabulary Practice</p>
        </div>
      </div>
    </div>
    );
  };

  // Flashcards Component
  //
  // Rebuilt so a flashcard round produces real evidence. Before, a card counted
  // as "completed" the moment it was flipped, whatever she knew, so a run could
  // only ever be logged as attempted = correct and the tracker learned nothing.
  // Now each card is rated Got it / Not yet, the deck is shuffled, cards she
  // did not get come back in another pass, and the round ends with the counts
  // the tracker actually wants: right first time, right after a retry, not got.
  const FlashcardsGame = () => {
    const allCards = vocabulary.map((item, index) => ({
      id: index + 1,
      word: item.word,
      english: item.english,
      pronunciation: item.pronunciation,
      category: 'word',
    }));

    const shuffle = (arr) => {
      const out = [...arr];
      for (let i = out.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [out[i], out[j]] = [out[j], out[i]];
      }
      return out;
    };

    const [deck, setDeck] = useState(() => shuffle(allCards));
    const [pass, setPass] = useState(1);
    const [flipped, setFlipped] = useState({});
    // id -> { passes, got } — passes counts how many rounds it took
    const [results, setResults] = useState({});
    const [startedAt] = useState(() => new Date().toISOString());
    const [finished, setFinished] = useState(false);
    const [copied, setCopied] = useState(false);

    const rated = deck.filter(c => results[c.id] && results[c.id].pass === pass);
    const notYet = deck.filter(c => results[c.id] && results[c.id].pass === pass && !results[c.id].got);
    const roundDone = rated.length === deck.length && deck.length > 0;

    const rate = (card, got) => {
      setResults(prev => ({
        ...prev,
        [card.id]: { got, pass, passes: (prev[card.id]?.passes || 0) + 1 },
      }));
    };

    const nextPass = () => {
      setDeck(shuffle(notYet));
      setPass(p => p + 1);
      setFlipped({});
    };

    const restart = () => {
      setDeck(shuffle(allCards));
      setPass(1);
      setFlipped({});
      setResults({});
      setFinished(false);
      setCopied(false);
    };

    // Outcome per card across the whole session, for the run report.
    const outcomes = allCards.map(card => {
      const r = results[card.id];
      if (!r) return { card, outcome: null, attempts: 0 };
      if (r.got) return { card, outcome: r.passes === 1 ? 'correct' : 'retry', attempts: r.passes };
      return { card, outcome: 'incorrect', attempts: r.passes };
    }).filter(o => o.outcome);

    const gotFirstTime = outcomes.filter(o => o.outcome === 'correct').length;
    const gotAfterRetry = outcomes.filter(o => o.outcome === 'retry').length;
    const notGot = outcomes.filter(o => o.outcome === 'incorrect').length;

    const report = buildRunReport({
      source: 'spanish_flashcards',
      label: `${vocabularySets[selectedSet].name} — flashcards`,
      startedAt,
      attempted: outcomes.length,
      correct: gotFirstTime,
      correctAfterRetry: gotAfterRetry,
      incorrect: notGot,
      metrics: { passes: pass },
      items: outcomes.map(o => ({
        prompt: o.card.word,
        outcome: o.outcome,
        attempts_taken: o.attempts,
        note: o.outcome === 'incorrect' ? 'flash-not-yet' : undefined,
      })),
    });

    const copyReport = () => {
      copyText(JSON.stringify(report, null, 2));
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    };

    if (finished) {
      return (
        <div className="min-h-screen bg-gradient-to-br from-blue-50 to-purple-50 p-8">
          <div className="max-w-2xl mx-auto bg-white rounded-3xl shadow-xl p-10 text-center">
            <CheckCircle size={64} className="mx-auto mb-4 text-green-500" />
            <h1 className="text-3xl font-bold text-gray-800 mb-6">Round finished</h1>
            <div className="grid grid-cols-3 gap-4 mb-6">
              <div className="bg-green-100 rounded-lg p-4">
                <div className="text-sm text-gray-600">Right first time</div>
                <div className="text-3xl font-bold text-green-600">{gotFirstTime}</div>
              </div>
              <div className="bg-yellow-100 rounded-lg p-4">
                <div className="text-sm text-gray-600">Got there after</div>
                <div className="text-3xl font-bold text-yellow-600">{gotAfterRetry}</div>
              </div>
              <div className="bg-gray-100 rounded-lg p-4">
                <div className="text-sm text-gray-600">Not yet</div>
                <div className="text-3xl font-bold text-gray-600">{notGot}</div>
              </div>
            </div>
            {notGot > 0 && (
              <p className="text-gray-600 mb-6">
                Still to come back to: {outcomes.filter(o => o.outcome === 'incorrect').map(o => o.card.word).join(', ')}
              </p>
            )}
            <div className="flex flex-wrap gap-3 justify-center">
              <button onClick={restart} className="bg-purple-600 text-white px-6 py-3 rounded-full font-bold shadow-md hover:bg-purple-700 transition-colors flex items-center gap-2">
                <RotateCcw size={18} /> Go again
              </button>
              <button onClick={copyReport} className="bg-gray-800 text-white px-6 py-3 rounded-full font-bold shadow-md hover:bg-gray-900 transition-colors">
                {copied ? 'Copied ✓' : 'Copy session report'}
              </button>
              <button onClick={() => setCurrentScreen('welcome')} className="bg-gray-200 text-gray-800 px-6 py-3 rounded-full font-bold shadow-md hover:bg-gray-300 transition-colors flex items-center gap-2">
                <Home size={18} /> Menu
              </button>
            </div>
            <p className="mt-6 text-sm text-gray-500">
              Paste the report to Claude at the end of the session and it goes into the tracker.
            </p>
          </div>
        </div>
      );
    }

    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-50 to-purple-50 p-8">
        <div className="max-w-6xl mx-auto">
          {/* Header */}
          <div className="text-center mb-8">
            <button
              onClick={() => setCurrentScreen('welcome')}
              className="mb-4 bg-gray-600 text-white px-4 py-2 rounded-full shadow-md hover:bg-gray-700 transition-colors flex items-center gap-2 mx-auto"
            >
              <Home size={18} />
              Back to Menu
            </button>
            <h1 className="text-4xl font-bold text-gray-800 mb-2">
              {LANGUAGE_NAME} Flashcards 📚
            </h1>
            <p className="text-gray-600 text-lg">
              Say it out loud first, then flip the card and tell it how you did.
            </p>

            {/* Progress */}
            <div className="mt-4 flex flex-wrap items-center justify-center gap-3">
              <div className="bg-white px-6 py-3 rounded-full shadow-md">
                <span className="text-gray-700 font-medium">
                  Round {pass} · rated {rated.length} / {deck.length}
                </span>
              </div>
              {gotFirstTime + gotAfterRetry > 0 && (
                <div className="bg-green-100 px-5 py-3 rounded-full shadow-md">
                  <span className="text-green-800 font-medium">Got {gotFirstTime + gotAfterRetry}</span>
                </div>
              )}
              <button
                onClick={restart}
                className="bg-purple-600 text-white px-4 py-3 rounded-full shadow-md hover:bg-purple-700 transition-colors flex items-center gap-2"
              >
                <RotateCcw size={18} />
                Shuffle and restart
              </button>
            </div>

            {roundDone && (
              <div className="mt-5 inline-flex flex-wrap gap-3 justify-center">
                {notYet.length > 0 ? (
                  <button
                    onClick={nextPass}
                    className="bg-gradient-to-r from-purple-600 to-blue-600 text-white px-6 py-3 rounded-full font-bold shadow-lg hover:shadow-xl transition-all"
                  >
                    Try the {notYet.length} you didn&apos;t get again
                  </button>
                ) : (
                  <div className="bg-green-100 text-green-800 px-6 py-3 rounded-full font-bold">
                    All of them, done ✓
                  </div>
                )}
                <button
                  onClick={() => setFinished(true)}
                  className="bg-gray-800 text-white px-6 py-3 rounded-full font-bold shadow-md hover:bg-gray-900 transition-colors"
                >
                  Finish and see the round
                </button>
              </div>
            )}
          </div>

          {/* Flashcards Grid */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {deck.map((card) => {
              const result = results[card.id];
              const ratedThisPass = result && result.pass === pass;
              return (
                <div key={card.id} className="relative group" style={{ perspective: '1000px' }}>
                  <div
                    className="relative w-full h-48 transition-transform duration-500"
                    style={{
                      transformStyle: 'preserve-3d',
                      transform: flipped[card.id] ? 'rotateY(180deg)' : 'rotateY(0deg)'
                    }}
                  >
                    {/* Front of card (target language) */}
                    <div
                      onClick={() => setFlipped(prev => ({ ...prev, [card.id]: true }))}
                      className={`absolute w-full h-full cursor-pointer bg-white rounded-2xl shadow-lg p-6 flex flex-col items-center justify-center border-4 transition-colors ${ratedThisPass ? (result.got ? 'border-green-400' : 'border-amber-400') : 'border-cyan-400 group-hover:border-cyan-500'}`}
                      style={{ backfaceVisibility: 'hidden' }}
                    >
                      {ratedThisPass && (
                        <div className="absolute top-3 right-3">
                          {result.got
                            ? <CheckCircle className="text-green-500" size={24} />
                            : <RotateCcw className="text-amber-500" size={24} />}
                        </div>
                      )}
                      <div className="text-sm font-semibold uppercase tracking-wide mb-2 text-cyan-600">
                        {card.category}
                      </div>
                      <div className="text-3xl font-bold text-gray-800 text-center">
                        {card.word}
                      </div>
                      {card.pronunciation && (
                        <div className="mt-2 text-sm text-gray-500 italic text-center">
                          ({card.pronunciation})
                        </div>
                      )}
                      <div className="mt-4 text-gray-500 text-sm">
                        Say it, then click to check
                      </div>
                    </div>

                    {/* Back of card (English) */}
                    <div
                      className="absolute w-full h-full bg-gradient-to-br from-green-400 to-emerald-500 rounded-2xl shadow-lg p-5 flex flex-col items-center justify-center border-4 border-green-600"
                      style={{
                        backfaceVisibility: 'hidden',
                        transform: 'rotateY(180deg)'
                      }}
                    >
                      <div className="text-3xl font-bold text-white text-center mb-4">
                        {card.english}
                      </div>
                      <div className="flex gap-2">
                        <button
                          onClick={() => { rate(card, true); setFlipped(prev => ({ ...prev, [card.id]: false })); }}
                          className="bg-white text-green-700 px-4 py-2 rounded-full font-bold shadow hover:bg-green-50 transition-colors"
                        >
                          Got it
                        </button>
                        <button
                          onClick={() => { rate(card, false); setFlipped(prev => ({ ...prev, [card.id]: false })); }}
                          className="bg-amber-100 text-amber-800 px-4 py-2 rounded-full font-bold shadow hover:bg-amber-200 transition-colors"
                        >
                          Not yet
                        </button>
                      </div>
                      <button
                        onClick={() => setFlipped(prev => ({ ...prev, [card.id]: false }))}
                        className="mt-3 text-white opacity-90 text-xs underline"
                      >
                        flip back without rating
                      </button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>
    );
  };

  // Shooting Gallery Component
  const ShootingGalleryGame = () => {
    const [score, setScore] = useState(0);
    const [fails, setFails] = useState(0);
    // A word that escaped and a word she picked wrongly are different things:
    // one is "too slow", the other is "didn't know". They used to be one
    // number, so the tracker could not tell them apart and 19-29% of items
    // arrived as "not got" with no way to see which were speed.
    const [timeouts, setTimeouts] = useState(0);
    const [wrongPicks, setWrongPicks] = useState(0);
    const [missedWords, setMissedWords] = useState([]);
    const [consecutiveFails, setConsecutiveFails] = useState(0);
    const [gameOver, setGameOver] = useState(false);
    const [gameStarted, setGameStarted] = useState(false);
    const [speed, setSpeed] = useState(6);
    // 'turbo' is the original ramp; 'steady' holds at 6x. Both are always
    // offered — nothing is unlocked or withheld.
    const [mode, setMode] = useState('turbo');
    // A review round runs with no clock at all, so a word she misses under
    // pressure can be met again without it.
    const [untimed, setUntimed] = useState(false);
    const [reviewWords, setReviewWords] = useState(null);
    const [startedAt, setStartedAt] = useState(null);
    const [copied, setCopied] = useState(false);
    const [currentWord, setCurrentWord] = useState(null);
    const [wordPosition, setWordPosition] = useState(100);
    const [feedback, setFeedback] = useState(null);
    const [selectedAnswer, setSelectedAnswer] = useState(null);
    const [answerOptions, setAnswerOptions] = useState([]);
    const [scoreHistory, setScoreHistory] = useState([]);
    const [historyLoaded, setHistoryLoaded] = useState(false);
    const [isNewBest, setIsNewBest] = useState(false);
    const HISTORY_KEY = 'shooting-gallery-history';
    const MAX_HISTORY = 20;
    const TURBO_FLOOR = -2;   // 12.0x
    const STEADY_FLOOR = 4;   // 6.0x

    // Load saved score history once on mount
    useEffect(() => {
      let cancelled = false;
      const loadHistory = async () => {
        try {
          if (window.storage) {
            const result = await window.storage.get(HISTORY_KEY);
            if (!cancelled && result && result.value) {
              const parsed = JSON.parse(result.value);
              if (Array.isArray(parsed)) {
                setScoreHistory(parsed);
              }
            }
          }
        } catch (err) {
          // No history saved yet - that's fine, start fresh
        } finally {
          if (!cancelled) setHistoryLoaded(true);
        }
      };
      loadHistory();
      return () => { cancelled = true; };
    }, []);

    // Use 80% current set + 20% previous sets, or just the review list
    const gameVocabulary = reviewWords || getMixedVocabulary(0.8);

    const getRandomWord = (pool = gameVocabulary) => {
      return pool[Math.floor(Math.random() * pool.length)];
    };

    const getAnswerOptions = (correctWord, pool = gameVocabulary) => {
      const options = [correctWord.english];
      const otherWords = pool.filter(w => w.english !== correctWord.english);

      // A review list can be shorter than three words, so stop when the pool
      // is exhausted rather than looping forever.
      while (options.length < 3 && otherWords.length > 0) {
        const randomWord = otherWords[Math.floor(Math.random() * otherWords.length)];
        if (!options.includes(randomWord.english)) {
          options.push(randomWord.english);
        }
        if (options.length >= otherWords.length + 1) break;
      }

      return options.sort(() => Math.random() - 0.5);
    };

    const recordMiss = (word, reason) => {
      if (!word) return;
      setMissedWords(prev => [...prev, {
        word: word.word,
        english: word.english,
        pronunciation: word.pronunciation,
        reason,
        speed: Number((10 - speed).toFixed(1)),
      }]);
    };

    const startGame = (opts = {}) => {
      const nextMode = opts.mode || mode;
      const pool = opts.reviewWords || getMixedVocabulary(0.8);
      setMode(nextMode);
      setReviewWords(opts.reviewWords || null);
      setUntimed(!!opts.untimed);
      setGameStarted(true);
      setScore(0);
      setFails(0);
      setTimeouts(0);
      setWrongPicks(0);
      setMissedWords([]);
      setConsecutiveFails(0);
      setGameOver(false);
      setIsNewBest(false);
      setCopied(false);
      setSpeed(6);
      setWordPosition(100);
      setStartedAt(new Date().toISOString());
      const word = getRandomWord(pool);
      setCurrentWord(word);
      setAnswerOptions(getAnswerOptions(word, pool));
    };

    // newScore is passed in because `score` inside this closure is the value
    // from before setScore ran, so the old `score % 3` test fired the ramp one
    // answer late — the game said "faster every 3" and sped up on the 4th.
    const nextWord = (wasCorrect, newScore) => {
      setWordPosition(100);
      setFeedback(null);
      setSelectedAnswer(null);
      const word = getRandomWord();
      setCurrentWord(word);
      setAnswerOptions(getAnswerOptions(word));

      if (!wasCorrect) return;
      setConsecutiveFails(0);
      if (untimed) return;

      const floor = mode === 'steady' ? STEADY_FLOOR : TURBO_FLOOR;
      const currentDisplaySpeed = 10 - speed;
      if (currentDisplaySpeed < 7.5) {
        // Below 7.5x: speed up every 3 correct answers
        if (newScore > 0 && newScore % 3 === 0) {
          setSpeed(prev => Math.max(floor, prev - 0.8));
        }
      } else {
        // 7.5x and above: speed up every 10 correct answers with very small steps
        if (newScore > 0 && newScore % 10 === 0) {
          setSpeed(prev => Math.max(floor, prev - 0.1));
        }
      }
    };

    const handleAnswer = (answer) => {
      if (feedback || !currentWord) return;

      setSelectedAnswer(answer);

      if (answer === currentWord.english) {
        const newScore = score + 1;
        setScore(newScore);
        setFeedback('correct');
        setTimeout(() => nextWord(true, newScore), 800);
      } else {
        recordMiss(currentWord, 'wrong');
        setWrongPicks(w => w + 1);
        setFails(prev => prev + 1);
        const newConsecutive = consecutiveFails + 1;
        setConsecutiveFails(newConsecutive);
        const ends = newConsecutive >= 3 && !untimed;
        if (ends) setGameOver(true);
        setFeedback('wrong');
        setTimeout(() => {
          if (!ends) nextWord(false, score);
        }, 800);
      }
    };

    // Movement only. The escape is handled in the effect below rather than
    // inside this state updater: React may call an updater twice, and a miss
    // counted twice is a miss she never made.
    useEffect(() => {
      if (!gameStarted || gameOver || feedback || untimed) return;

      const interval = setInterval(() => {
        setWordPosition(prev => prev - 1);
      }, speed * 10);

      return () => clearInterval(interval);
    }, [gameStarted, gameOver, feedback, speed, untimed]);

    // The word got away.
    useEffect(() => {
      if (!gameStarted || gameOver || feedback || untimed) return;
      if (wordPosition >= -10) return;

      recordMiss(currentWord, 'timeout');
      setTimeouts(t => t + 1);
      setFails(f => f + 1);
      const newConsecutive = consecutiveFails + 1;
      setConsecutiveFails(newConsecutive);
      if (newConsecutive >= 3) {
        setGameOver(true);
        setWordPosition(100);
      } else {
        nextWord(false, score);
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wordPosition, gameStarted, gameOver, feedback, untimed]);

    // Keyboard controls for answer buttons
    useEffect(() => {
      if (!gameStarted || gameOver || feedback) return;

      const handleKeyDown = (event) => {
        if (event.key === 'ArrowLeft' && answerOptions[0]) {
          handleAnswer(answerOptions[0]);
        } else if (event.key === 'ArrowDown' && answerOptions[1]) {
          handleAnswer(answerOptions[1]);
        } else if (event.key === 'ArrowRight' && answerOptions[2]) {
          handleAnswer(answerOptions[2]);
        }
      };

      window.addEventListener('keydown', handleKeyDown);

      return () => {
        window.removeEventListener('keydown', handleKeyDown);
      };
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [gameStarted, gameOver, feedback, answerOptions]);

    // Record the completed game to history once it ends. A review round is
    // practice, not a score, so it never sets a record or joins the trend.
    useEffect(() => {
      if (!gameOver || !historyLoaded || untimed) return;

      const accuracy = score + fails > 0 ? Number(((score / (score + fails)) * 100).toFixed(1)) : 0;
      const priorBest = scoreHistory.length > 0 ? Math.max(...scoreHistory.map(h => h.score)) : 0;
      const newEntry = {
        score,
        fails,
        timeouts,
        wrongPicks,
        missed: missedWords.map(m => m.word),
        mode,
        accuracy,
        topSpeed: Number((10 - speed).toFixed(1)),
        date: new Date().toISOString(),
      };

      setIsNewBest(score > priorBest);

      setScoreHistory(prev => {
        const updated = [...prev, newEntry].slice(-MAX_HISTORY);
        if (window.storage) {
          window.storage.set(HISTORY_KEY, JSON.stringify(updated)).catch(() => {});
        }
        return updated;
      });
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [gameOver, historyLoaded]);

    // The run as the tracker wants it: attempted = correct + incorrect, one
    // item per word she missed, with why it was missed and the speed she was
    // on when it happened.
    const runReport = buildRunReport({
      source: 'spanish_gallery',
      label: untimed
        ? `${vocabularySets[selectedSet].name} — review, no timer`
        : `Shooting Gallery — ${vocabularySets[selectedSet].name}`,
      startedAt: startedAt || new Date().toISOString(),
      attempted: score + fails,
      correct: score,
      incorrect: fails,
      metrics: untimed
        ? { untimed: 1 }
        : { top_speed: Number((10 - speed).toFixed(1)), timeouts, wrong_picks: wrongPicks },
      items: missedWords.map(m => ({
        prompt: m.word,
        outcome: 'incorrect',
        note: m.reason === 'timeout' ? `timeout at ${m.speed}x` : `wrong pick at ${m.speed}x`,
      })),
    });

    const copyReport = () => {
      copyText(JSON.stringify(runReport, null, 2));
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    };

    // The words she missed, de-duplicated, for the review round.
    const missedUnique = missedWords.filter(
      (m, i, arr) => arr.findIndex(x => x.word === m.word) === i
    );

    const bestScore = scoreHistory.length > 0 ? Math.max(...scoreHistory.map(h => h.score), score) : score;
    const recentGames = [...scoreHistory].slice(-8).reverse();

    // Build a simple inline sparkline path for score trend (last 10 games)
    const chartGames = scoreHistory.slice(-10);
    const buildChartPath = () => {
      if (chartGames.length < 2) return null;
      const w = 560;
      const h = 140;
      const padding = 20;
      const maxScore = Math.max(...chartGames.map(g => g.score), 1);
      const stepX = (w - padding * 2) / (chartGames.length - 1);
      const points = chartGames.map((g, i) => {
        const x = padding + i * stepX;
        const y = h - padding - (g.score / maxScore) * (h - padding * 2);
        return { x, y, score: g.score };
      });
      const pathD = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
      return { pathD, points, w, h };
    };
    const chart = buildChartPath();

    const clearHistory = () => {
      setScoreHistory([]);
      if (window.storage) {
        window.storage.delete(HISTORY_KEY).catch(() => {});
      }
    };

    if (!gameStarted) {
      return (
        <div className="min-h-screen bg-gradient-to-br from-purple-600 to-blue-600 flex items-center justify-center p-8">
          <div className="bg-white rounded-3xl shadow-2xl p-12 max-w-2xl text-center">
            <button
              onClick={() => setCurrentScreen('welcome')}
              className="mb-6 bg-gray-600 text-white px-4 py-2 rounded-full shadow-md hover:bg-gray-700 transition-colors flex items-center gap-2 mx-auto"
            >
              <Home size={18} />
              Back to Menu
            </button>
            <Target size={80} className="mx-auto mb-6 text-purple-600" />
            <h1 className="text-5xl font-bold text-gray-800 mb-4">
              {LANGUAGE_NAME} Shooting Gallery! 🎯
            </h1>
            <p className="text-xl text-gray-600 mb-8">
              Shoot down the {LANGUAGE_NAME} words by clicking the correct English translation before they escape!
            </p>
            <div className="bg-purple-50 rounded-xl p-6 mb-8 text-left">
              <h2 className="text-lg font-bold text-gray-800 mb-3">How to Play:</h2>
              <ul className="space-y-2 text-gray-700">
                <li>✓ {LANGUAGE_NAME} words will scroll up the screen</li>
                <li>✓ Click the correct English translation, or use ← ↓ →</li>
                <li>✓ Turbo speeds up every 3 you get right; Steady holds at 6x</li>
                <li>✓ Game ends after 3 misses in a row</li>
                <li>✓ Whatever you miss comes back at the end, with no timer</li>
              </ul>
            </div>
            <div className="flex flex-wrap gap-4 justify-center">
              <button
                onClick={() => startGame({ mode: 'turbo' })}
                className="bg-gradient-to-r from-purple-600 to-blue-600 text-white px-10 py-4 rounded-full text-2xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
              >
                Turbo
              </button>
              <button
                onClick={() => startGame({ mode: 'steady' })}
                className="bg-white text-purple-700 border-2 border-purple-600 px-10 py-4 rounded-full text-2xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
              >
                Steady
              </button>
            </div>
            <p className="mt-4 text-sm text-gray-500">
              Steady keeps one speed the whole game, so a miss means you didn&apos;t know it
              rather than that it went past too fast. Both count the same.
            </p>
          </div>
        </div>
      );
    }

    if (gameOver) {
      return (
        <div className="min-h-screen bg-gradient-to-br from-red-600 to-orange-600 flex items-center justify-center p-8">
          <div className="bg-white rounded-3xl shadow-2xl p-8 md:p-12 max-w-3xl w-full text-center">
            <Trophy size={80} className="mx-auto mb-6 text-yellow-500" />
            <h1 className="text-5xl font-bold text-gray-800 mb-4">
              Game Over!
            </h1>

            {isNewBest && (
              <div className="mb-4 inline-block bg-gradient-to-r from-yellow-400 to-orange-400 text-white font-bold px-6 py-2 rounded-full shadow-md animate-pulse">
                🏆 New Best Score!
              </div>
            )}

            <div className="bg-gray-50 rounded-xl p-8 mb-6">
              <div className="text-6xl font-bold text-purple-600 mb-2">
                {score}
              </div>
              <div className="text-xl text-gray-600 mb-4">Final Score</div>
              <div className="grid grid-cols-3 gap-4 text-left">
                <div className="bg-green-100 rounded-lg p-4">
                  <div className="text-sm text-gray-600">Correct</div>
                  <div className="text-3xl font-bold text-green-600">{score}</div>
                </div>
                <div className="bg-red-100 rounded-lg p-4">
                  <div className="text-sm text-gray-600">Missed</div>
                  <div className="text-3xl font-bold text-red-600">{fails}</div>
                </div>
                <div className="bg-yellow-100 rounded-lg p-4">
                  <div className="text-sm text-gray-600">Best Score</div>
                  <div className="text-3xl font-bold text-yellow-600 flex items-center gap-1">
                    <Trophy size={22} className="text-yellow-500" />
                    {bestScore}
                  </div>
                </div>
              </div>
            </div>

            {/* Score trend chart */}
            {chart && (
              <div className="bg-gray-50 rounded-xl p-6 mb-6 text-left">
                <h2 className="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Score Trend (last {chartGames.length} games)</h2>
                <svg viewBox={`0 0 ${chart.w} ${chart.h}`} className="w-full h-32">
                  <path d={chart.pathD} fill="none" stroke="#9333ea" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />
                  {chart.points.map((p, i) => (
                    <g key={i}>
                      <circle cx={p.x} cy={p.y} r="4" fill="#9333ea" />
                      <text x={p.x} y={p.y - 10} textAnchor="middle" fontSize="11" fill="#6b21a8" fontWeight="bold">
                        {p.score}
                      </text>
                    </g>
                  ))}
                </svg>
              </div>
            )}

            {/* Recent games table */}
            {recentGames.length > 0 && (
              <div className="bg-gray-50 rounded-xl p-6 mb-8 text-left">
                <h2 className="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Recent Games</h2>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-gray-500 border-b border-gray-200">
                        <th className="text-left py-2 pr-2">Date</th>
                        <th className="text-right py-2 px-2">Score</th>
                        <th className="text-right py-2 px-2">Missed</th>
                        <th className="text-right py-2 px-2">Accuracy</th>
                        <th className="text-right py-2 pl-2">Top Speed</th>
                      </tr>
                    </thead>
                    <tbody>
                      {recentGames.map((game, i) => (
                        <tr key={i} className={`border-b border-gray-100 ${game.score === bestScore ? 'bg-yellow-50 font-semibold' : ''}`}>
                          <td className="py-2 pr-2 text-gray-600">
                            {new Date(game.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
                          </td>
                          <td className="text-right py-2 px-2 text-purple-600 font-bold">
                            {game.score}{game.score === bestScore && ' 🏆'}
                          </td>
                          <td className="text-right py-2 px-2 text-red-500">{game.fails}</td>
                          <td className="text-right py-2 px-2">{game.accuracy}%</td>
                          <td className="text-right py-2 pl-2 text-yellow-600">{game.topSpeed}x</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <button
                  onClick={clearHistory}
                  className="mt-4 text-xs text-gray-400 hover:text-red-500 transition-colors underline"
                >
                  Clear history
                </button>
              </div>
            )}

            {/* The words that got away. The moment she most wants to fix
                something is the moment the old screen showed her nothing. */}
            {missedUnique.length > 0 && (
              <div className="bg-amber-50 rounded-xl p-6 mb-6 text-left">
                <h2 className="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">
                  These got away ({missedUnique.length})
                </h2>
                <div className="flex flex-wrap gap-2 mb-4">
                  {missedUnique.map((m, i) => (
                    <span key={i} className="bg-white border border-amber-300 rounded-full px-3 py-1 text-sm">
                      <b>{m.word}</b> — {m.english}
                      <span className="text-gray-400 ml-1 text-xs">
                        {m.reason === 'timeout' ? `too slow at ${m.speed}x` : 'wrong pick'}
                      </span>
                    </span>
                  ))}
                </div>
                <button
                  onClick={() => startGame({ reviewWords: missedUnique.map(m => ({
                    word: m.word, english: m.english, pronunciation: m.pronunciation,
                  })), untimed: true })}
                  className="bg-amber-500 text-white px-6 py-3 rounded-full font-bold shadow-md hover:bg-amber-600 transition-colors"
                >
                  Practise these — no timer
                </button>
              </div>
            )}

            <div className="flex flex-wrap gap-4 justify-center">
              <button
                onClick={() => startGame({ mode })}
                className="bg-gradient-to-r from-purple-600 to-blue-600 text-white px-8 py-4 rounded-full text-xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
              >
                Play Again!
              </button>
              <button
                onClick={copyReport}
                className="bg-gray-800 text-white px-8 py-4 rounded-full text-xl font-bold shadow-lg hover:shadow-xl transition-all"
              >
                {copied ? 'Copied ✓' : 'Copy session report'}
              </button>
              <button
                onClick={() => setCurrentScreen('welcome')}
                className="bg-gray-600 text-white px-8 py-4 rounded-full text-xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
              >
                Main Menu
              </button>
            </div>
            <p className="mt-4 text-sm text-gray-500">
              Paste the report to Claude at the end of the session — that is what puts
              this game on your chart.
            </p>
          </div>
        </div>
      );
    }

    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-900 to-purple-900 relative overflow-hidden">
        {/* Score Board */}
        <div className="absolute top-0 left-0 right-0 bg-black bg-opacity-50 p-4 z-10">
          <div className="max-w-4xl mx-auto flex justify-between items-center text-white">
            <button
              onClick={() => setCurrentScreen('welcome')}
              className="bg-gray-600 text-white px-4 py-2 rounded-full shadow-md hover:bg-gray-700 transition-colors flex items-center gap-2"
            >
              <Home size={18} />
              Menu
            </button>
            <div className="flex gap-8">
              <div>
                <div className="text-sm opacity-75">Score</div>
                <div className="text-3xl font-bold">{score}</div>
              </div>
              <div>
                <div className="text-sm opacity-75">Fails</div>
                <div className="text-3xl font-bold text-red-400">{fails}</div>
              </div>
              <div>
                <div className="text-sm opacity-75">Accuracy</div>
                <div className="text-3xl font-bold text-blue-400">
                  {score + fails > 0 ? ((score / (score + fails)) * 100).toFixed(1) : '0.0'}%
                </div>
              </div>
              <div>
                <div className="text-sm opacity-75">Speed</div>
                <div className="text-3xl font-bold text-yellow-400">
                  {untimed ? 'off' : `${(10 - speed).toFixed(1)}x`}
                </div>
              </div>
            </div>
            {untimed ? (
              <button
                onClick={() => setGameOver(true)}
                className="bg-amber-500 text-white px-5 py-2 rounded-full font-bold shadow-md hover:bg-amber-600 transition-colors"
              >
                Done
              </button>
            ) : (
              <div className="flex gap-2">
                {[...Array(3)].map((_, i) => (
                  <div
                    key={i}
                    className={`w-4 h-4 rounded-full ${
                      i < consecutiveFails ? 'bg-red-500' : 'bg-gray-600'
                    }`}
                  />
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Game Area */}
        <div className="h-screen flex items-end justify-center p-8 pt-32">
          <div className="relative w-full max-w-4xl h-full">
            {/* Moving target-language word */}
            {currentWord && (
              <div
                className="absolute left-1/2 transform -translate-x-1/2 transition-all"
                style={{
                  bottom: `${wordPosition}%`,
                  transition: feedback ? 'all 0.3s' : 'none'
                }}
              >
                <div className={`text-6xl font-bold px-8 py-4 rounded-2xl shadow-2xl ${
                  feedback === 'correct' 
                    ? 'bg-green-500 text-white scale-110' 
                    : feedback === 'wrong'
                    ? 'bg-red-500 text-white scale-90 opacity-50'
                    : 'bg-white text-gray-800'
                }`}>
                  {currentWord.word}
                  {feedback === 'correct' && <span className="ml-4">✓</span>}
                  {feedback === 'wrong' && <span className="ml-4">✗</span>}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Answer Buttons */}
        <div className="absolute bottom-8 left-0 right-0">
          <div className="max-w-4xl mx-auto grid grid-cols-3 gap-4 px-8">
            {answerOptions.map((option, index) => (
              <button
                key={index}
                onClick={() => handleAnswer(option)}
                disabled={!!feedback}
                className={`py-6 px-4 rounded-xl text-xl font-bold transition-all transform hover:scale-105 disabled:cursor-not-allowed ${
                  selectedAnswer === option && feedback === 'correct'
                    ? 'bg-green-500 text-white shadow-lg'
                    : selectedAnswer === option && feedback === 'wrong'
                    ? 'bg-red-500 text-white shadow-lg'
                    : 'bg-white text-gray-800 hover:bg-blue-100 shadow-lg'
                }`}
              >
                {option}
              </button>
            ))}
          </div>
        </div>
      </div>
    );
  };

  // AI Chat Game Component
  const AIChatGame = () => {
    const [messages, setMessages] = useState([]);
    const [userInput, setUserInput] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [score, setScore] = useState({ correct: 0, partly: 0, incorrect: 0 });
    // The behavioural metric the Spanish plan actually names: how many time
    // frames she manages across a session.
    const [timeFrames, setTimeFrames] = useState([]);
    const [startedAt] = useState(() => new Date().toISOString());
    const [copied, setCopied] = useState(false);
    const [gameStarted, setGameStarted] = useState(false);
    const [currentPromptType, setCurrentPromptType] = useState('');

    // Use 60% current set + 40% previous sets
    const gameVocabulary = getMixedVocabulary(0.6);
    const vocabularyList = gameVocabulary.map(v => `${v.word} (${v.english})`).join(', ');

    const generatePrompt = async () => {
      // The four shapes are the speaking tasks she will actually sit, not
      // generic chat: role-play, photo card, the compulsory questions, and
      // free conversation.
      const promptTypes = [
        'role_play',
        'photo_card',
        'four_questions',
        'conversation'
      ];

      const randomType = promptTypes[Math.floor(Math.random() * promptTypes.length)];
      setCurrentPromptType(randomType);

      const taskBrief = {
        role_play: `Set a short role-play in a everyday situation (a shop, a station, a friend's house). Give her the situation in English in one line, then ONE thing to say or ask in ${LANGUAGE_NAME}.`,
        photo_card: `Describe an imaginary photo in one line of English (who is in it, where they are, what they are doing), then ask her to describe it in ${LANGUAGE_NAME}.`,
        four_questions: `Ask ONE straightforward personal question in English that she should answer in ${LANGUAGE_NAME} — the kind an examiner asks about home, school, free time or family.`,
        conversation: `Say something friendly and real in English, and ask her one follow-up question she can answer in ${LANGUAGE_NAME}.`,
      }[randomType];

      let systemPrompt = `You are helping a ${STUDENT.age}-year-old student practising ${STUDENT.level}. The vocabulary she is working on is: ${vocabularyList}.

Task type for this turn: ${randomType}.
${taskBrief}

Rules:
- The prompt is in English; her answer will be in ${LANGUAGE_NAME}.
- One task only. Never ask two things in one message.
- Keep it under 30 words, warm and ordinary — no exclamation-mark pile-ups.
- Never include ${LANGUAGE_NAME} model answers or vocabulary translations in the prompt; she has to produce the language herself.

Only output the prompt itself, nothing else.`;

      try {
        const response = await fetch('https://api.anthropic.com/v1/messages', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            model: 'claude-sonnet-4-20250514',
            max_tokens: 1000,
            messages: [
              { role: 'user', content: systemPrompt }
            ],
          }),
        });

        const data = await response.json();
        const prompt = data.content.find(c => c.type === 'text')?.text || `Tell me something using your vocabulary in ${LANGUAGE_NAME}!`;
        
        return prompt;
      } catch (error) {
        console.error('Error generating prompt:', error);
        return `Tell me something using your vocabulary in ${LANGUAGE_NAME}!`;
      }
    };

    const checkAnswer = async (userAnswer) => {
      // Marked the way the speaking test is marked: did the message get
      // across, fully or partly. A single red cross for one agreement slip is
      // both wrong and discouraging.
      //
      // "hint" is a hint, never the answer. Handing her the corrected sentence
      // is the one thing shown to leave students worse off once the help is
      // taken away, and it is the same rule her maths tutoring follows.
      const systemPrompt = `You are marking one spoken-style answer from a ${STUDENT.age}-year-old studying ${STUDENT.level}.

The vocabulary she is working on is: ${vocabularyList}

The task was: "${messages[messages.length - 1].text}"
Her answer was: "${userAnswer}"

Judge it as an examiner would judge a role-play task:
- conveyed 2: the message got across without ambiguity
- conveyed 1: partly across, or ambiguous
- conveyed 0: not across
Be lenient about accents and spelling. Errors that do not change the meaning are minor.
Count how many time frames she used successfully (present, past, future).

Respond in this exact JSON format (no markdown, no other text):
{
  "conveyed": 0, 1 or 2,
  "time_frames": ["present"],
  "major_errors": 0,
  "minor_errors": 0,
  "feedback": "one or two sentences, second person, naming what she actually did",
  "hint": "if conveyed is 0 or 1: name the structure or word class to fix, 12 words max"
}

Rules for hint: name the thing to fix ("the verb ending for -er verbs in the past"), never write the corrected sentence or the missing word. If conveyed is 2, hint is "".
Rules for feedback: specific and plain. No "amazing", "perfect", "incredible", "brilliant". Never praise her as a person, only what she did.`;

      try {
        const response = await fetch('https://api.anthropic.com/v1/messages', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            model: 'claude-sonnet-4-20250514',
            max_tokens: 1000,
            messages: [
              { role: 'user', content: systemPrompt }
            ],
          }),
        });

        const data = await response.json();
        const responseText = data.content.find(c => c.type === 'text')?.text || '{}';
        const cleanResponse = responseText.replace(/```json|```/g, '').trim();
        const evaluation = JSON.parse(cleanResponse);
        
        return evaluation;
      } catch (error) {
        console.error('Error checking answer:', error);
        // failed: not a judgement about her answer, so it must not be scored
        // either way.
        return {
          failed: true,
          feedback: "I couldn't check that one just now — the connection dropped. Your score hasn't changed.",
          hint: ""
        };
      }
    };

    const startGame = async () => {
      setGameStarted(true);
      setMessages([]);
      setScore({ correct: 0, partly: 0, incorrect: 0 });
      setTimeFrames([]);
      setIsLoading(true);
      
      try {
        const prompt = await generatePrompt();
        setMessages([{ type: 'ai', text: prompt }]);
      } catch (error) {
        console.error('Error starting game:', error);
        setMessages([{ type: 'ai', text: `Hi! Tell me something using your vocabulary — in ${LANGUAGE_NAME}! 💬` }]);
      }
      setIsLoading(false);
    };

    const handleSubmit = async (e) => {
      e.preventDefault();
      console.log('handleSubmit called!');
      console.log('User input:', userInput);
      console.log('Is loading:', isLoading);
      
      if (!userInput.trim() || isLoading) {
        console.log('Returning early - input empty or loading');
        return;
      }

      const userMessage = userInput.trim();
      setUserInput('');
      setMessages(prev => [...prev, { type: 'user', text: userMessage }]);
      setIsLoading(true);

      try {
        const evaluation = await checkAnswer(userMessage);

        // A failed check is not a wrong answer and not a right one.
        const conveyed = typeof evaluation.conveyed === 'number' ? evaluation.conveyed : null;
        if (!evaluation.failed && conveyed !== null) {
          if (conveyed === 2) {
            setScore(prev => ({ ...prev, correct: prev.correct + 1 }));
          } else if (conveyed === 1) {
            setScore(prev => ({ ...prev, partly: prev.partly + 1 }));
          } else {
            setScore(prev => ({ ...prev, incorrect: prev.incorrect + 1 }));
          }
          if (Array.isArray(evaluation.time_frames)) {
            setTimeFrames(prev => Array.from(new Set([...prev, ...evaluation.time_frames])));
          }
        }

        let feedbackMessage = evaluation.feedback;
        if (conveyed !== null && conveyed < 2 && evaluation.hint) {
          feedbackMessage += ` 💡 Look at: ${evaluation.hint}`;
        }

        setMessages(prev => [...prev, {
          type: 'feedback',
          text: feedbackMessage,
          conveyed,
          failed: !!evaluation.failed,
        }]);

        setTimeout(async () => {
          try {
            const nextPrompt = await generatePrompt();
            setMessages(prev => [...prev, { type: 'ai', text: nextPrompt }]);
          } catch (err) {
            setMessages(prev => [...prev, { type: 'ai', text: `Tell me another sentence using your ${LANGUAGE_NAME} vocabulary!` }]);
          }
          setIsLoading(false);
        }, 1500);
      } catch (error) {
        console.error('Error in handleSubmit:', error);
        // The old version congratulated her and added a point here, so an
        // outage inflated the score and a run logged from this screen counted
        // answers nobody had marked.
        setMessages(prev => [...prev, {
          type: 'feedback',
          text: "Something went wrong at my end, so that one isn't marked. Your score hasn't changed — have another go.",
          failed: true,
        }]);
        setTimeout(() => {
          setMessages(prev => [...prev, { type: 'ai', text: `Can you try another sentence in ${LANGUAGE_NAME}?` }]);
          setIsLoading(false);
        }, 1500);
      }
    };

    if (!gameStarted) {
      return (
        <div className="min-h-screen bg-gradient-to-br from-emerald-600 to-teal-600 flex items-center justify-center p-8">
          <div className="bg-white rounded-3xl shadow-2xl p-12 max-w-2xl text-center">
            <button
              onClick={() => setCurrentScreen('welcome')}
              className="mb-6 bg-gray-600 text-white px-4 py-2 rounded-full shadow-md hover:bg-gray-700 transition-colors flex items-center gap-2 mx-auto"
            >
              <Home size={18} />
              Back to Menu
            </button>
            <Zap size={80} className="mx-auto mb-6 text-emerald-600" />
            <h1 className="text-5xl font-bold text-gray-800 mb-4">
              AI Chat Practice! 💬
            </h1>
            <p className="text-xl text-gray-600 mb-8">
              Have real conversations and practice your {LANGUAGE_NAME} with an AI friend!
            </p>
            <div className="bg-emerald-50 rounded-xl p-6 mb-8 text-left">
              <h2 className="text-lg font-bold text-gray-800 mb-3">How it works:</h2>
              <ul className="space-y-2 text-gray-700">
                <li>✓ AI will ask you questions or start conversations</li>
                <li>✓ Respond in {LANGUAGE_NAME} using your vocabulary</li>
                <li>✓ Get instant feedback on your answers</li>
                <li>✓ Track your score as you practice</li>
                <li>✓ Learn from mistakes with helpful suggestions</li>
              </ul>
            </div>
            <button
              onClick={startGame}
              className="bg-gradient-to-r from-emerald-600 to-teal-600 text-white px-12 py-4 rounded-full text-2xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
            >
              Start Chatting!
            </button>
          </div>
        </div>
      );
    }

    return (
      <div className="min-h-screen bg-gradient-to-br from-emerald-50 to-teal-50 p-8">
        <div className="max-w-4xl mx-auto">
          {/* Header */}
          <div className="bg-white rounded-2xl shadow-lg p-6 mb-6">
            <div className="flex justify-between items-center">
              <button
                onClick={() => setCurrentScreen('welcome')}
                className="bg-gray-600 text-white px-4 py-2 rounded-full shadow-md hover:bg-gray-700 transition-colors flex items-center gap-2"
              >
                <Home size={18} />
                Menu
              </button>
              <h1 className="text-3xl font-bold text-gray-800">AI Chat Practice 💬</h1>
              <div className="flex gap-6 items-end">
                <div className="text-center">
                  <div className="text-sm text-gray-600">Got across</div>
                  <div className="text-3xl font-bold text-green-600">{score.correct}</div>
                </div>
                <div className="text-center">
                  <div className="text-sm text-gray-600">Partly</div>
                  <div className="text-3xl font-bold text-amber-600">{score.partly}</div>
                </div>
                <div className="text-center">
                  <div className="text-sm text-gray-600">Not yet</div>
                  <div className="text-3xl font-bold text-gray-500">{score.incorrect}</div>
                </div>
                <div className="text-center">
                  <div className="text-sm text-gray-600">Time frames</div>
                  <div className="text-3xl font-bold text-blue-600">{timeFrames.length}<span className="text-lg text-gray-400">/3</span></div>
                </div>
                <button
                  onClick={() => {
                    copyText(JSON.stringify(buildRunReport({
                      source: 'spanish_chat',
                      label: `AI Chat — ${vocabularySets[selectedSet].name}`,
                      startedAt,
                      attempted: score.correct + score.partly + score.incorrect,
                      correct: score.correct,
                      correctAfterRetry: score.partly,
                      incorrect: score.incorrect,
                      metrics: { time_frames: timeFrames.length },
                    }), null, 2));
                    setCopied(true);
                    setTimeout(() => setCopied(false), 2500);
                  }}
                  className="bg-gray-800 text-white px-4 py-2 rounded-full text-sm font-bold shadow hover:bg-gray-900 transition-colors"
                >
                  {copied ? 'Copied ✓' : 'Copy report'}
                </button>
              </div>
            </div>
          </div>

          {/* Chat Messages */}
          <div className="bg-white rounded-2xl shadow-lg p-6 mb-6 h-96 overflow-y-auto">
            <div className="space-y-4">
              {messages.map((message, index) => (
                <div
                  key={index}
                  className={`flex ${message.type === 'user' ? 'justify-end' : 'justify-start'}`}
                >
                  <div
                    className={`max-w-xs lg:max-w-md px-6 py-4 rounded-2xl ${
                      message.type === 'user'
                        ? 'bg-blue-500 text-white'
                        : message.type === 'feedback'
                        ? message.failed
                          ? 'bg-gray-100 text-gray-700 border-2 border-gray-300'
                          : message.conveyed === 2
                          ? 'bg-green-100 text-green-800 border-2 border-green-400'
                          : message.conveyed === 1
                          ? 'bg-amber-100 text-amber-900 border-2 border-amber-400'
                          : 'bg-gray-100 text-gray-800 border-2 border-gray-400'
                        : 'bg-emerald-500 text-white'
                    }`}
                  >
                    {message.type === 'ai' && <div className="text-xs opacity-75 mb-1">AI</div>}
                    {message.type === 'feedback' && (
                      <div className="text-xs font-bold mb-1">
                        {message.failed
                          ? 'not marked'
                          : message.conveyed === 2
                          ? 'Message got across'
                          : message.conveyed === 1
                          ? 'Partly across'
                          : 'Not across yet'}
                      </div>
                    )}
                    <div className="text-base">{message.text}</div>
                  </div>
                </div>
              ))}
              {isLoading && (
                <div className="flex justify-start">
                  <div className="bg-gray-200 text-gray-600 px-6 py-4 rounded-2xl">
                    <div className="flex gap-2">
                      <div className="w-2 h-2 bg-gray-600 rounded-full animate-bounce"></div>
                      <div className="w-2 h-2 bg-gray-600 rounded-full animate-bounce" style={{ animationDelay: '0.2s' }}></div>
                      <div className="w-2 h-2 bg-gray-600 rounded-full animate-bounce" style={{ animationDelay: '0.4s' }}></div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Input Form */}
          <form onSubmit={handleSubmit} className="bg-white rounded-2xl shadow-lg p-6">
            <div className="flex gap-4">
              <input
                type="text"
                value={userInput}
                onChange={(e) => setUserInput(e.target.value)}
                onKeyPress={(e) => {
                  if (e.key === 'Enter' && !isLoading && userInput.trim()) {
                    handleSubmit({ preventDefault: () => {} });
                  }
                }}
                placeholder={`Type your ${LANGUAGE_NAME} response here...`}
                disabled={isLoading}
                className="flex-1 px-6 py-4 text-lg border-2 border-gray-300 rounded-xl focus:border-emerald-500 focus:outline-none disabled:bg-gray-100"
              />
              <button
                type="button"
                onClick={() => {
                  console.log('Send button clicked!');
                  console.log('User input:', userInput);
                  console.log('Is loading:', isLoading);
                  handleSubmit({ preventDefault: () => {} });
                }}
                disabled={isLoading || !userInput.trim()}
                className="bg-gradient-to-r from-emerald-600 to-teal-600 text-white px-8 py-4 rounded-xl text-lg font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Send
              </button>
            </div>
            <div className="mt-4 text-sm text-gray-600 text-center">
              Use the vocabulary you learned: {gameVocabulary.map(v => v.word).join(', ')}
            </div>
          </form>
        </div>
      </div>
    );
  };

  // Main render
  return (
    <>
      {currentScreen === 'welcome' && <WelcomeScreen />}
      {currentScreen === 'flashcards' && <FlashcardsGame />}
      {currentScreen === 'shooting-gallery' && <ShootingGalleryGame />}
      {currentScreen === 'ai-chat' && <AIChatGame />}
    </>
  );
};

export default LanguageLearningApp;