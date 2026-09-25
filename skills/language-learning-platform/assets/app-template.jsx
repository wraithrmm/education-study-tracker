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

  // Get mixed vocabulary for games
  const getMixedVocabulary = (currentPercent) => {
    const current = getCurrentVocabulary();
    const previous = getPreviousVocabulary();
    
    if (previous.length === 0) {
      return current; // If no previous sets, just use current
    }

    const currentCount = Math.floor(current.length * currentPercent);
    const previousCount = current.length - currentCount;
    
    const mixed = [...current.slice(0, currentCount)];
    
    // Add random words from previous sets
    for (let i = 0; i < previousCount; i++) {
      const randomPrevious = previous[Math.floor(Math.random() * previous.length)];
      mixed.push(randomPrevious);
    }
    
    return mixed;
  };
  
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
          <p className="text-lg">Key Stage 3 {LANGUAGE_NAME} • Vocabulary Practice</p>
        </div>
      </div>
    </div>
    );
  };

  // Flashcards Component
  const FlashcardsGame = () => {
    const [flippedCards, setFlippedCards] = useState({});
    const [completedCards, setCompletedCards] = useState({});

    const flashcards = vocabulary.map((item, index) => {
      // Simple default category — customize this if you want per-set visual
      // groupings (e.g. verbs vs. nouns), keyed off `selectedSet` and `index`.
      const category = 'word';

      return {
        id: index + 1,
        word: item.word,
        english: item.english,
        pronunciation: item.pronunciation,
        category: category
      };
    });

    const toggleFlip = (id) => {
      setFlippedCards(prev => ({
        ...prev,
        [id]: !prev[id]
      }));
      
      if (!flippedCards[id]) {
        setCompletedCards(prev => ({
          ...prev,
          [id]: true
        }));
      }
    };

    const resetAll = () => {
      setFlippedCards({});
      setCompletedCards({});
    };

    const completedCount = Object.keys(completedCards).length;

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
            <p className="text-gray-600 text-lg">Click any card to reveal the English translation</p>
            
            {/* Progress */}
            <div className="mt-4 flex items-center justify-center gap-4">
              <div className="bg-white px-6 py-3 rounded-full shadow-md">
                <span className="text-gray-700 font-medium">
                  Progress: {completedCount} / {flashcards.length}
                </span>
              </div>
              <button
                onClick={resetAll}
                className="bg-purple-600 text-white px-4 py-3 rounded-full shadow-md hover:bg-purple-700 transition-colors flex items-center gap-2"
              >
                <RotateCcw size={18} />
                Reset All
              </button>
            </div>
          </div>

          {/* Flashcards Grid */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {flashcards.map((card) => (
              <div
                key={card.id}
                onClick={() => toggleFlip(card.id)}
                className="relative cursor-pointer group"
                style={{ perspective: '1000px' }}
              >
                <div
                  className={`relative w-full h-48 transition-transform duration-500 transform-style-3d`}
                  style={{
                    transformStyle: 'preserve-3d',
                    transform: flippedCards[card.id] ? 'rotateY(180deg)' : 'rotateY(0deg)'
                  }}
                >
                  {/* Front of card (target language) */}
                  <div
                    className="absolute w-full h-full backface-hidden bg-white rounded-2xl shadow-lg p-6 flex flex-col items-center justify-center border-4 border-cyan-400 group-hover:border-cyan-500 transition-colors"
                    style={{ backfaceVisibility: 'hidden' }}
                  >
                    {completedCards[card.id] && (
                      <div className="absolute top-3 right-3">
                        <CheckCircle className="text-green-500" size={24} />
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
                      Click to reveal
                    </div>
                  </div>

                  {/* Back of card (English) */}
                  <div
                    className="absolute w-full h-full backface-hidden bg-gradient-to-br from-green-400 to-emerald-500 rounded-2xl shadow-lg p-6 flex flex-col items-center justify-center border-4 border-green-600"
                    style={{
                      backfaceVisibility: 'hidden',
                      transform: 'rotateY(180deg)'
                    }}
                  >
                    <div className="text-sm font-semibold uppercase tracking-wide mb-2 text-white opacity-90">
                      English
                    </div>
                    <div className="text-3xl font-bold text-white text-center">
                      {card.english}
                    </div>
                    <div className="mt-4 text-white opacity-90 text-sm">
                      Click to flip back
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Completion Message */}
          {completedCount === flashcards.length && (
            <div className="mt-8 text-center bg-gradient-to-r from-green-400 to-emerald-500 text-white p-6 rounded-2xl shadow-lg">
              <h2 className="text-2xl font-bold mb-2">🎉 ¡Excelente! Well Done!</h2>
              <p className="text-lg">You've practiced all {flashcards.length} flashcards!</p>
            </div>
          )}
        </div>
      </div>
    );
  };

  // Shooting Gallery Component
  const ShootingGalleryGame = () => {
    const [score, setScore] = useState(0);
    const [fails, setFails] = useState(0);
    const [consecutiveFails, setConsecutiveFails] = useState(0);
    const [gameOver, setGameOver] = useState(false);
    const [gameStarted, setGameStarted] = useState(false);
    const [speed, setSpeed] = useState(6);
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

    // Use 80% current set + 20% previous sets
    const gameVocabulary = getMixedVocabulary(0.8);

    const getRandomWord = () => {
      return gameVocabulary[Math.floor(Math.random() * gameVocabulary.length)];
    };

    const getAnswerOptions = (correctWord) => {
      const options = [correctWord.english];
      const otherWords = gameVocabulary.filter(w => w.english !== correctWord.english);
      
      while (options.length < 3) {
        const randomWord = otherWords[Math.floor(Math.random() * otherWords.length)];
        if (!options.includes(randomWord.english)) {
          options.push(randomWord.english);
        }
      }
      
      return options.sort(() => Math.random() - 0.5);
    };

    const startGame = () => {
      setGameStarted(true);
      setScore(0);
      setFails(0);
      setConsecutiveFails(0);
      setGameOver(false);
      setSpeed(6);
      setWordPosition(100);
      const word = getRandomWord();
      setCurrentWord(word);
      setAnswerOptions(getAnswerOptions(word));
    };

    const nextWord = (wasCorrect) => {
      setWordPosition(100);
      setFeedback(null);
      setSelectedAnswer(null);
      const word = getRandomWord();
      setCurrentWord(word);
      setAnswerOptions(getAnswerOptions(word));
      
      if (wasCorrect) {
        setConsecutiveFails(0);
        // Speed up more gently at higher speeds
        const currentDisplaySpeed = 10 - speed;
        if (currentDisplaySpeed < 7.5) {
          // Below 7.5x: speed up every 3 correct answers
          if (score > 0 && score % 3 === 0) {
            setSpeed(prev => Math.max(-2, prev - 0.8));
          }
        } else {
          // 7.5x and above: speed up every 10 correct answers with very small steps
          if (score > 0 && score % 10 === 0) {
            setSpeed(prev => Math.max(-2, prev - 0.1));
          }
        }
      }
    };

    const handleAnswer = (answer) => {
      if (feedback || !currentWord) return;
      
      setSelectedAnswer(answer);
      
      if (answer === currentWord.english) {
        setScore(prev => prev + 1);
        setFeedback('correct');
        setTimeout(() => nextWord(true), 800);
      } else {
        setFails(prev => prev + 1);
        setConsecutiveFails(prev => {
          const newFails = prev + 1;
          if (newFails >= 3) {
            setGameOver(true);
          }
          return newFails;
        });
        setFeedback('wrong');
        setTimeout(() => {
          if (consecutiveFails < 2) {
            nextWord(false);
          }
        }, 800);
      }
    };

    useEffect(() => {
      if (!gameStarted || gameOver || feedback) return;

      const interval = setInterval(() => {
        setWordPosition(prev => {
          const newPos = prev - 1;
          if (newPos < -10) {
            setFails(f => f + 1);
            setConsecutiveFails(cf => {
              const newFails = cf + 1;
              if (newFails >= 3) {
                setGameOver(true);
              }
              return newFails;
            });
            nextWord(false);
            return 100;
          }
          return newPos;
        });
      }, speed * 10);

      return () => clearInterval(interval);
    }, [gameStarted, gameOver, feedback, speed]);

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
    }, [gameStarted, gameOver, feedback, answerOptions]);

    // Record the completed game to history once it ends
    useEffect(() => {
      if (!gameOver || !historyLoaded) return;

      const accuracy = score + fails > 0 ? Number(((score / (score + fails)) * 100).toFixed(1)) : 0;
      const priorBest = scoreHistory.length > 0 ? Math.max(...scoreHistory.map(h => h.score)) : 0;
      const newEntry = {
        score,
        fails,
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
                <li>✓ Click the correct English translation to shoot them down</li>
                <li>✓ Game gets faster every 3 points</li>
                <li>✓ Game ends after 3 fails in a row</li>
                <li>✓ Don't let words escape off the screen!</li>
              </ul>
            </div>
            <button
              onClick={startGame}
              className="bg-gradient-to-r from-purple-600 to-blue-600 text-white px-12 py-4 rounded-full text-2xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
            >
              Start Game!
            </button>
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

            <div className="flex gap-4 justify-center">
              <button
                onClick={startGame}
                className="bg-gradient-to-r from-purple-600 to-blue-600 text-white px-8 py-4 rounded-full text-xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
              >
                Play Again!
              </button>
              <button
                onClick={() => setCurrentScreen('welcome')}
                className="bg-gray-600 text-white px-8 py-4 rounded-full text-xl font-bold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all"
              >
                Main Menu
              </button>
            </div>
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
                <div className="text-3xl font-bold text-yellow-400">{(10 - speed).toFixed(1)}x</div>
              </div>
            </div>
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
    const [score, setScore] = useState({ correct: 0, incorrect: 0 });
    const [gameStarted, setGameStarted] = useState(false);
    const [currentPromptType, setCurrentPromptType] = useState('');

    // Use 60% current set + 40% previous sets
    const gameVocabulary = getMixedVocabulary(0.6);
    const vocabularyList = gameVocabulary.map(v => `${v.word} (${v.english})`).join(', ');

    const generatePrompt = async () => {
      const promptTypes = [
        'friend_casual',
        'teacher_question',
        'conversation_starter',
        'practice_prompt',
        'scenario'
      ];
      
      const randomType = promptTypes[Math.floor(Math.random() * promptTypes.length)];
      setCurrentPromptType(randomType);

      let systemPrompt = `You are helping a 12-year-old Key Stage 3 student practice ${LANGUAGE_NAME} vocabulary. The vocabulary they're learning is: ${vocabularyList}.

Your role varies:
- Sometimes act like a friendly classmate having a casual chat
- Sometimes act like an encouraging teacher
- Sometimes create fun scenarios that require using the vocabulary
- Keep it age-appropriate, fun, and engaging

Generate a prompt that will make the student respond using one or more of these ${LANGUAGE_NAME} words or phrases. The prompt should be in English, but should naturally lead to a ${LANGUAGE_NAME} response.

Examples:
- "Hey! What's your favourite thing to do? Tell me in ${LANGUAGE_NAME}!"
- "Imagine you're chatting with a new friend abroad — how would you introduce yourself?"
- "Can you describe your day so far, using some of your vocabulary?"

Make it varied and creative. Keep it short and friendly. Only output the prompt itself, nothing else.`;

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
      const systemPrompt = `You are evaluating a 12-year-old ${LANGUAGE_NAME} learner's response. 

The vocabulary they're learning is: ${vocabularyList}

The prompt was: "${messages[messages.length - 1].text}"
Their response was: "${userAnswer}"

Evaluate if their ${LANGUAGE_NAME} response:
1. Uses the vocabulary correctly
2. Makes sense as an answer to the prompt
3. Has correct grammar (be lenient, they're learning!)

Respond in this exact JSON format (no markdown, no other text):
{
  "correct": true or false,
  "feedback": "friendly feedback message",
  "suggestion": "if incorrect, show the correct way"
}

Be encouraging! If they tried and were close, give them credit. Be specific about what they did well.`;

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
        return {
          correct: false,
          feedback: "Sorry, I had trouble checking your answer. Try again!",
          suggestion: ""
        };
      }
    };

    const startGame = async () => {
      setGameStarted(true);
      setMessages([]);
      setScore({ correct: 0, incorrect: 0 });
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
        
        if (evaluation.correct) {
          setScore(prev => ({ ...prev, correct: prev.correct + 1 }));
        } else {
          setScore(prev => ({ ...prev, incorrect: prev.incorrect + 1 }));
        }

        let feedbackMessage = evaluation.feedback;
        if (!evaluation.correct && evaluation.suggestion) {
          feedbackMessage += ` 💡 Try: "${evaluation.suggestion}"`;
        }

        setMessages(prev => [...prev, { 
          type: 'feedback', 
          text: feedbackMessage,
          correct: evaluation.correct 
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
        setMessages(prev => [...prev, { 
          type: 'feedback', 
          text: "Great try! Keep practicing! 🎉",
          correct: true 
        }]);
        setScore(prev => ({ ...prev, correct: prev.correct + 1 }));
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
              <div className="flex gap-6">
                <div className="text-center">
                  <div className="text-sm text-gray-600">Correct</div>
                  <div className="text-3xl font-bold text-green-600">{score.correct}</div>
                </div>
                <div className="text-center">
                  <div className="text-sm text-gray-600">Incorrect</div>
                  <div className="text-3xl font-bold text-red-600">{score.incorrect}</div>
                </div>
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
                        ? message.correct
                          ? 'bg-green-100 text-green-800 border-2 border-green-400'
                          : 'bg-red-100 text-red-800 border-2 border-red-400'
                        : 'bg-emerald-500 text-white'
                    }`}
                  >
                    {message.type === 'ai' && <div className="text-xs opacity-75 mb-1">AI</div>}
                    {message.type === 'feedback' && (
                      <div className="text-xs font-bold mb-1">
                        {message.correct ? '✓ Great job!' : '✗ Not quite'}
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