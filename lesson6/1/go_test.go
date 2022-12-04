package main

import (
	"fmt"
	"strconv"
	"testing"
)

func TestEmptyStack(t *testing.T) {
	var st = Stack[int]{}
	if st.Size() != 0 {
		t.Error("Error")
	}
}

func TestSizeNotEmpty(t *testing.T) {
	var st = Stack[int]{}
	st.Push(1)
	st.Push(2)
	st.Push(3)
	st.Push(4)
	st.Push(5)
	st.Push(6)

	if st.Size() != 6 {
		t.Error("Error")
	}
}

func TestPeekEmpty(t *testing.T) {
	var st = Stack[int]{}
	var _, error = st.Peek()

	if error == nil {
		t.Error("Error")
	}
}

func TestPopEmpty(t *testing.T) {
	var st = Stack[int]{}
	var _, error = st.Pop()

	if error == nil {
		t.Error("Error")
	}

}

func TestPeekNotEmpty(t *testing.T) {
	var st = Stack[int]{}
	st.Push(1)
	st.Push(2)
	var result, error = st.Peek()

	if error != nil {
		t.Error("Error")
	}

	if result != 2 {
		t.Error("Error")
	}

	if st.Size() != 2 {
		t.Error("Error")
	}
}

func TestPopNotEmpty(t *testing.T) {
	var st = Stack[int]{}
	st.Push(1)
	st.Push(2)
	var result, error = st.Pop()

	if error != nil {
		t.Error("Error")
	}

	if result != 2 {
		t.Error("Error")
	}

	if st.Size() != 1 {
		t.Error("Error")
	}
}

func TestPeekAndPush(t *testing.T) {
	var st = Stack[int]{}

	for j := 1; j <= 1000; j++ {
		st.Push(j)
	}

	if st.Size() != 1000 {
		t.Error("Error")
	}

	for j := 0; j < 1000; j++ {
		var result, error = st.Pop()
		if result != (1000 - j) {
			t.Error("Error")
		}
		if error != nil {
			t.Error("Error")
		}
	}

	if st.Size() != 0 {
		t.Error("Error")
	}

	for j := 1; j <= 1000; j++ {
		st.Push(j)
	}

	if st.Size() != 1000 {
		t.Error("Error")
	}
	var result, error = st.Peek()

	if result != 1000 {
		t.Error("Error")
	}
	if error != nil {
		t.Error("Error")
	}
}

/**
Напишите функцию, которая получает на вход строку, состоящую из открывающих и закрывающих скобок (например, "(()((())()))" или "(()()(()") и, и
спользуя только стек и оператор цикла, определите, сбалансированы ли скобки в этой строке. Сбалансированной считается последовательность,
в которой каждой открывающей обязательно соответствует закрывающая, а каждой закрывающей
-- открывающая скобки, то есть последовательности "())(" , "))((" или "((())" будут несбалансированы.
*/

func TestCalculateBalanceString(t *testing.T) {

	var test = []string{"(()((())()))", "(()()(()", "())(", "))((", "((())", "()(()())()((()())())"}

	for i := 0; i < len(test); i++ {
		var checkString string = test[i]
		if checkString[0] == ')' || len(checkString)%2 != 0 {
			fmt.Println(checkString, "- cтрока не сбалансирована")
			continue
		}

		var st = Stack[string]{}

		for j := 0; j < len(checkString); j++ {
			if checkString[j] == '(' {
				st.Push("j")
			} else {
				st.Pop()
			}
		}
		if st.Size() > 0 {
			fmt.Println(checkString, "- cтрока не сбалансирована")
		} else {
			fmt.Println(checkString, " - cтрока сбалансирована")
		}
	}

}

/**
5. бонус +500 золотых. Постфиксная запись выражения -- это запись, в которой порядок вычислений определяется не скобками и приоритетами,
а только позицией элемента в выражении. Например, в выражениях разрешено использовать целые числа и операции + и * . Тогда выражение
*/

func TestCalculate(t *testing.T) {

	var test = []string{"1 2 + 3 * =", "8 2 + 5 * 9 + =", "2 3 *", "2 3 + 2 * ="}

	for i := 0; i < len(test); i++ {
		var checkString string = test[i]

		var st1 = Stack[string]{}
		var st2 = Stack[int]{}

		for j := 0; j < len(checkString); j++ {

			if string(checkString[j]) == " " {
				continue
			}
			st1.Push(string(checkString[j]))
		}

		for {
			if st1.Size() == 0 {
				break
			}

			var currentChar, _ = st1.Pop()
			if digit, err := strconv.Atoi(currentChar); err == nil {
				st2.Push(digit)
			} else {
				if currentChar == "+" {
					var first, _ = st2.Pop()
					var second, _ = st2.Pop()
					st2.Push(first + second)
				} else if currentChar == "*" {
					var first, _ = st2.Pop()
					var second, _ = st2.Pop()
					st2.Push(first * second)
				} else if currentChar == "=" {
					var result, _ = st2.Pop()
					fmt.Println(checkString, " = ", result)
					break
				}
			}

		}

	}

}